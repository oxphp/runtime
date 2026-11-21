# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

- `composer install` — install dev deps
- `vendor/bin/phpunit` — full test suite (config: `phpunit.xml.dist`, bootstrap: `tests/bootstrap.php`)
- `vendor/bin/phpunit --filter MethodName tests/Unit/Path/To/File.php` — single test
- `vendor/bin/php-cs-fixer fix` — apply formatting (rules in `.php-cs-fixer.dist.php`)
- `vendor/bin/php-cs-fixer fix --dry-run --diff` — check formatting without writing

PHPUnit is configured with `failOnWarning`, `failOnNotice`, and `failOnRisky` all true — warnings are failures.

## Public API surface

Only three classes are public:
- `OxPHP\Runtime\Runtime` — the runtime entry point (referenced from `APP_RUNTIME` and `composer.json`'s `extra.runtime.class`).
- `OxPHP\Runtime\Resetter\ResetterInterface` — for user-written worker-mode resetters.
- `OxPHP\Runtime\Symfony\OxPHPBundle` — opt-in Symfony bundle (one line in `config/bundles.php`) that isolates per-request services between overlapping requests. Its container parameters `oxphp_runtime.fiber_isolation` and `oxphp_runtime.fiber_isolation_reason` are public too.

Everything under `OxPHP\Runtime\Internal\` is `@internal` and may change without a major version bump. Don't add cross-package imports of `Internal\` symbols and don't expose them in public docs.

## Architecture

`Runtime` extends `Symfony\Component\Runtime\SymfonyRuntime`. It overrides `getRunner()` and dispatches by the type of object returned from `index.php`:

| Returned object | Runner |
|---|---|
| `Psr\Http\Server\RequestHandlerInterface` | `Psr15Runner` |
| `Illuminate\Contracts\Http\Kernel` | `LaravelRunner` |
| `Symfony\Component\HttpKernel\HttpKernelInterface` | `HttpKernelRunner` |
| `Symfony\Component\HttpFoundation\Response` | `HttpFoundationResponseRunner` (one-shot) |
| `Psr\Http\Message\ResponseInterface` | `Psr7ResponseRunner` (one-shot) |
| anything else | `LogicException` |

When `oxphp_server_info()` is not declared (i.e. not running under the OxPHP SAPI — local dev with PHP-FPM, the built-in server, or CI), `Runtime::getRunner()` falls back to `parent::getRunner()`. **This fallback path is load-bearing** — don't introduce code in `Runtime` that touches OxPHP extension functions before the fallback check.

### One-shot vs worker loop

`AbstractHttpRunner::run()` chooses based on `oxphp_is_worker()`:
- **Traditional**: one call to `handleRequest()`, return.
- **Worker**: `oxphp_worker(fn)` is called once and loops internally; each iteration opens a request scope (`RequestScopes::enterRequest()`, which returns a ticket), calls `handleRequest()` inside `try/catch(\Throwable)`, then runs the resetter chain and closes the scope (`leaveRequest($ticket)`) in `finally`. A broken handler must never poison the worker — preserve this invariant.

`onWorkerBoot()`/`onWorkerShutdown()` hooks exist on the abstract for subclasses, currently unused.

**Boot-time `$_SERVER` carry-over.** OxPHP rebuilds `$_SERVER` for every worker request, but the boot (dotenv inside `SymfonyRuntime::__construct()`, `APP_ENV`/`APP_DEBUG`, the `index.php` callable) runs once. `Runtime` snapshots `$_SERVER` *before* `parent::__construct()` and passes the diff (`bootServerVars()`) to the worker runners, which do `$_SERVER += $bootServer` at the top of each iteration — the request's own values always win. It is a diff on purpose: the boot's `$_SERVER` is a synthetic request (`REQUEST_URI=/`, `REMOTE_ADDR=127.0.0.1`) that would otherwise leak into requests lacking those keys (`SUPERGLOBALS_ENABLED=false`). `$_ENV` is not touched — OxPHP 0.11.0+ pins it for the worker's life.

### Bridge

`Internal\Bridge\OxPHP` is the only place that calls `oxphp_*()` functions for the runtime's own use (the runners still call a few directly — `oxphp_worker`, `oxphp_request_id`). When you need a new SAPI function, add a method here rather than scattering global function calls across runners.

### Request building

`HttpFoundationRequestBuilder` constructs a Symfony `Request` from `OxPHP\Http\RequestInterface` without reading `$_SERVER` — OxPHP can be configured with `SUPERGLOBALS_ENABLED=false`. `LaravelRunner` then wraps it via `IlluminateRequest::createFromBase()`. `Psr15Runner` builds PSR-7 directly via the resolved PSR-17 factory.

### PSR-17 factory discovery

`Internal\Emitter\Psr17FactoryLocator` probes installed factories in order: Nyholm → Guzzle → HttpSoft → Laminas. HttpSoft and Laminas split factories across four classes; the locator wraps both shapes into a `Psr17Factories` quad so runners see one interface. An explicit FQCN or instance via the `psr17_factory` runtime option overrides discovery.

### Streaming

`ResponseEmitter::emit()` accepts `iterable<string>|string`. For iterables it calls `oxphp_stream_flush()` after each chunk so OxPHP writes to the wire immediately (SSE, chunked transfer).

`ResponseEmitter::emitCallback()` drives a Symfony `StreamedResponse` callback **live**: a chunk-size-1 pass-through `ob_start` handler lets the engine write each `echo` straight to the SAPI, and `ob_implicit_flush(true)` makes the engine hit OxPHP's flush hook after every write — chunks are never collected and replayed after the callback returns, and user code need not call `flush()`. The handler must **not** call `oxphp_stream_flush()` itself (OxPHP raises "Cannot use output buffering in output buffering display handlers"); the single bridge flush happens in the `finally`, in main context. A throw mid-callback closes every buffer opened at or above ours before rethrowing, so the worker's buffer stack stays balanced. If you change response handling, preserve these contracts.

`Internal\Runner\HttpFoundationResponseEmitter` is the one place that maps Symfony response shapes onto emitter calls; `HttpKernelRunner`, `LaravelRunner`, and `HttpFoundationResponseRunner` all delegate to it rather than duplicating the streamed/non-streamed branch.

### Resetters

`ResetterChain` runs each resetter in a try/catch — a faulty resetter is logged via `error_log()` but never aborts the chain (workers must always reach a clean state). User resetters come from the `resetters` runtime option and run after every request, even while other requests are in flight. `HttpKernelRunner` automatically prepends `SymfonyContainerResetter` to the chain when the kernel is a full `Symfony\Component\HttpKernel\Kernel`. Laravel users get no built-in resetters — Octane-style flush of singletons/DB connections is up to user code.

`SymfonyContainerResetter` reads the `oxphp_runtime.fiber_isolation` container parameter lazily on each call (the container only exists after the first `handle()`):
- **Marker `true`, worker not degraded:** no-op. No teardown — Symfony's own `services_resetter` (guarded, see below) resets `kernel.reset` services at the start of the next request on an idle worker. Except once a request bailed out (`RequestScopes::hasBailedOut()`): a bailout inside `Kernel::handle()` leaves Symfony's private request depth raised, so `Kernel::boot()` never resets again, and the resetter calls `services_resetter` itself at the end of every request while `inFlight() <= 1`. No log line (a client leaving is normal), no reflection on Symfony privates.
- **Marker `true`, worker degraded:** teardown (`Container::reset()`: resets `ResetInterface` services, drops every instance), plus the degraded hint.
- **Marker absent or `false`:** the same teardown. If a request ends while another is in flight (`inFlight() > 1`; the resetters run before the scope closes, so the caller counts as one), it logs a hint via `error_log()`: no bundle, or bundle withheld with the recorded reason.

Each hint is logged at most once per resetter instance (one per worker). Don't drop the teardown for the marker-absent case — apps without the bundle keep today's behaviour.

### Fiber isolation (Symfony)

OxPHP runs overlapping requests in separate fibers on one worker. Symfony's per-request services are shared, so `OxPHPBundle` swaps them for per-request versions (issue #5). Rules to preserve:

- **`Internal\Fiber\RequestScopes`** is a static registry (static so the runner, outside the container, can open scopes and a container rebuild can't lose in-flight ones). `AbstractHttpRunner::runWorker()` calls `enterRequest()` first in each iteration (before `$_SERVER += $bootServer`) and `leaveRequest($ticket)` last, in the `finally` after the resetter chain (nested `finally`, so a throwing resetter still closes it). It does this for every `AbstractHttpRunner` subclass (`HttpKernelRunner`, `LaravelRunner`, `Psr15Runner`); the one-shot runners never open a scope.
- **Tickets and the shutdown close.** A fatal error, a time limit, `exit()`, a cancel, or a client gone mid-stream without `ignore_user_abort(true)` skips every `finally` of the request, but OxPHP still runs that request's own shutdown functions. So right after `enterRequest()` the runner registers `leaveRequest($ticket, atShutdown: true)` through `Bridge\OxPHP::registerRequestShutdown()` (the only `register_shutdown_function()` call; it must stay the iteration's first callback and write nothing). `Ticket` closes at most once, so the `finally` and the shutdown close can't count twice, and it unmaps the fiber only while the fiber still maps to its own scope (a recycled fiber keeps its next request's scope). A ticket still open at shutdown marks the registry bailed out for the worker's life (`hasBailedOut()`). Don't detect bailouts from `\Fiber` state: OxPHP recycles fibers.
- **OxPHP reuses `\Fiber` objects** across requests, so `enterRequest()` always mints a fresh `RequestScope` for the current fiber and replaces any stale mapping. Never key per-request state on the fiber alone, and never on `oxphp_request_id()` (client-controlled via `X-Request-ID`).
- `RequestScopes::current()`: `enterRequest()` never called (FPM, CLI, tests, traditional mode) → one shared scope, i.e. stock behaviour, including inside user fibers; worker mode and no fiber → shared scope; a registered request fiber → its scope; any other fiber (one the app created inside a request) → a private empty scope in a `WeakMap` (fails closed, never another request's state).
- **Degraded worker.** If `enterRequest()` finds no current fiber (OxPHP before 0.15.0 can return `null` from `Fiber::getCurrent()` after a fatal error inside a fiber), the registry marks itself degraded for the worker's life and `SymfonyContainerResetter` goes back to the teardown. This detects the symptom directly — no version check, no `oxphp_*()` call.
- **Never call `oxphp_*()` functions or `OxPHP\Server\*` from the bundle or the scoped services.** The container compiles under CLI (`cache:clear`, `cache:warmup`) where they don't exist. They may use only `\Fiber::getCurrent()` and `RequestScopes`. The `Runtime::getRunner()` fallback stays untouched.
- **Scoped services** live in `Internal\Symfony\FiberScoped*`: `request_stack`, `router.request_context` (decorated), `security.untracked_token_storage`, `security.token_storage` (`FiberScopedUsageTrackingTokenStorage` — the usage-tracking flag is per request too), `security.logout_url_generator`, the translator (`translator.default`, decorated, `kernel.locale_aware` tag moved to the decorator) and `translation.locale_switcher`. Each delegates to a stock Symfony object held per scope via `RequestScopes::current()->get($token, fn)`, keyed by a private token object rather than `$this` so `clone` works (Symfony clones the request stack and router context). SecurityBundle's context listeners stay stock: the pass only checks each carries the static `kernel.response` listener tag.
- **`FiberIsolationPass` is all-or-nothing.** It collects every reason a present service can't be scoped (custom class that `setClass()` would clobber, a listed id that is an alias (except `security.token_storage` aliased to `security.untracked_token_storage`, which is scoped itself), a public parent method the scoped class doesn't declare — checked by reflection via `OverrideCheck` at compile time, a context listener without the static tag, a `translator.default` without a class, with a missing class or one missing a translator interface, a `services_resetter` class implementing neither `ServicesResetterInterface`, http-kernel < 7.3). With any reason it changes nothing, sets the marker `false` and records the reasons (parameter and `ContainerBuilder::log()`); otherwise it installs everything and sets the marker `true`. It withholds instead of throwing for anything it detects, so `cache:warmup` keeps working after a Symfony upgrade. Absent service ids are skipped (routing, SecurityBundle, translator are optional). The first check must stay first: it withholds when `Kernel::VERSION_ID < 70300` (read from the version, since DependencyInjection 8.1 installs alongside any http-kernel from 6.4) or when neither `ServicesResetterInterface` exists (DependencyInjection's checked before HttpKernel's). Scoped classes can't even be loaded against an older parent, and autoloading HttpKernel's interface on 8.1 triggers a deprecation.
- **The `services_resetter` guard** (`AbstractGuardedServicesResetter`) is picked by the interface the `services_resetter` class implements (`is_a()`, which doesn't autoload the interface): HttpKernel's, checked first (7.3+; on 8.1 it extends DependencyInjection's, and HttpKernel's 8.1 `ServicesResetter` implements both) → `LegacyGuardedServicesResetter`, otherwise DependencyInjection's (8.1+, where `ServicesResetterInterface` moved) → `GuardedServicesResetter`. Not by which interfaces exist: FrameworkBundle 8.0 with DependencyInjection 8.1 still registers HttpKernel's. It decorates `services_resetter` and calls the inner reset only when `inFlight() <= 1`; Symfony reaches it at almost every request start, so there is no pending flag. Under continuous overlap shared `kernel.reset` services wait for an idle worker.
- Adding a scoped service: extend the pass's lists (and `OverrideCheck` exemptions for methods that keep no per-request state), cover it in a unit test with `RequestFiber` and in the integration test, and keep it stock outside worker mode.

## Tests

- `tests/bootstrap.php` declares polyfills only when the real `oxphp_sapi` extension isn't loaded.
- `tests/polyfill/oxphp_http_interfaces.php` mirrors `oxphp.stub.php` from the oxphp server repo. **These signatures are duplicated, not generated — when the upstream stub changes, update this file in lockstep.**
- `tests/Stub/OxPHPHarness` is a singleton that backs every `oxphp_*()` polyfill function. Tests `OxPHPHarness::reset()` between cases, then `setWorker()`/`pushRequest()`/`setCurrentRequest()` to script the SAPI behaviour, and read `flushCount()`/`finishCount()`/`capturedOutputs()`/`capturedStatuses()` for assertions. `driveWorker()` simulates the SAPI soft reset (each request starts at status 200; captures stdout and the final HTTP status per iteration; with `setRequestServer()`, each request also starts with that exact `$_SERVER`, and the real one is put back afterwards).
- `driveWorker()` runs each iteration in a fresh `\Fiber` (as OxPHP does), so fiber-keyed code sees a current fiber; a handler that suspends outside a `RequestFiber` scenario throws `LogicException`. With `setBailoutMode(true)` a suspend scripts a bailout instead: the iteration ends without its `finally`, its fiber is parked (`bailouts()` counts them) and `reset()` releases parked fibers quietly. Callbacks registered with `registerShutdown()` run at the end of their own iteration; `tests/bootstrap.php` routes the bridge's `registerRequestShutdown()` there via `OxPHP::replaceShutdownRegistrarForTests()`.
- `tests/Stub/RequestFiber` plays an OxPHP request fiber for overlap tests: `RequestFiber::start($body)` calls `RequestScopes::enterRequest()` on entry and `leaveRequest()` with its ticket when the body ends; bodies pause with `\Fiber::suspend()`, and the test drives `resume()`, `isTerminated()` and `result()`.
- `RequestScopes` is static, so every test class that touches it or a scoped service calls `RequestScopes::resetForTests()` in `setUp()` **and** `tearDown()` — otherwise worker mode leaks into later tests. Tests that go through the locale switcher also save and restore `\Locale::getDefault()`.
- The `default` suite includes `tests/Integration`: `FiberIsolationKernelTest` boots a real FrameworkBundle + SecurityBundle kernel (`Integration/Symfony/App/TestKernel`) with `OxPHPBundle`, overlaps requests with `RequestFiber` (routes suspend on `?suspend=1`), and checks request stack, locale, login persistence, `logout_path()`, `kernel.reset` timing (also after a bailout, through `HttpKernelRunner` and the harness), a fiber the app creates inside a request, and stock behaviour outside worker mode. On http-kernel < 7.3 only the withholding and stock-behaviour tests run. Extend it, rather than the unit stubs, for anything that depends on how Symfony wires its services.
- Test stubs in `tests/Stub/Apps/` (`FakeSymfonyKernel`, `FakeLaravelKernel`, `FakePsr15Handler`) are minimal — extend rather than replace when a runner test needs new behaviour.

## Coding style

- PHP **8.4+** required; constructor property promotion, readonly, asymmetric visibility are all in play.
- `declare(strict_types=1);` everywhere.
- Rules: `@PER-CS2.0` + `@PER-CS2.0:risky`, `@PSR12`, `@PHP84Migration`, `@PHP83Migration`, `@PhpCsFixer:risky`.
- `native_function_invocation` is set to `strict` with scope `all` — prefix every PHP built-in call with `\` (e.g. `\sprintf`, `\is_array`, `\headers_sent`). Same for native constants (`\PHP_INT_MAX`, etc.) via `native_constant_invocation`.

## Out of scope (see TODO.md)

Console apps, Closure/string/int returns from the index callable, populating superglobals, and ReactPHP/Swoole/RoadRunner compatibility are explicit non-goals. If a feature request matches one of these, point at TODO.md rather than implementing.
