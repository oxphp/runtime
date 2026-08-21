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

Only two classes are public:
- `OxPHP\Runtime\Runtime` — the runtime entry point (referenced from `APP_RUNTIME` and `composer.json`'s `extra.runtime.class`).
- `OxPHP\Runtime\Resetter\ResetterInterface` — for user-written worker-mode resetters.

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
- **Worker**: `oxphp_worker(fn)` is called once and loops internally; each iteration calls `handleRequest()` inside `try/catch(\Throwable)`, then runs the resetter chain in `finally`. A broken handler must never poison the worker — preserve this invariant.

`onWorkerBoot()`/`onWorkerShutdown()` hooks exist on the abstract for subclasses, currently unused.

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

`ResetterChain` runs each resetter in a try/catch — a faulty resetter is logged via `error_log()` but never aborts the chain (workers must always reach a clean state). User resetters come from the `resetters` runtime option. `HttpKernelRunner` automatically prepends `SymfonyContainerResetter` to the chain when the kernel is a full `Symfony\Component\HttpKernel\Kernel` (so `kernel.reset`-tagged services are reset between requests). Laravel users get no built-in resetters — Octane-style flush of singletons/DB connections is up to user code.

## Tests

- `tests/bootstrap.php` declares polyfills only when the real `oxphp_sapi` extension isn't loaded.
- `tests/polyfill/oxphp_http_interfaces.php` mirrors `oxphp.stub.php` from the oxphp server repo. **These signatures are duplicated, not generated — when the upstream stub changes, update this file in lockstep.**
- `tests/Stub/OxPHPHarness` is a singleton that backs every `oxphp_*()` polyfill function. Tests `OxPHPHarness::reset()` between cases, then `setWorker()`/`pushRequest()`/`setCurrentRequest()` to script the SAPI behaviour, and read `flushCount()`/`finishCount()`/`capturedOutputs()` for assertions. `driveWorker()` simulates the SAPI soft reset (resets HTTP status, captures stdout per iteration).
- Test stubs in `tests/Stub/Apps/` (`FakeSymfonyKernel`, `FakeLaravelKernel`, `FakePsr15Handler`) are minimal — extend rather than replace when a runner test needs new behaviour.

## Coding style

- PHP **8.4+** required; constructor property promotion, readonly, asymmetric visibility are all in play.
- `declare(strict_types=1);` everywhere.
- Rules: `@PER-CS2.0` + `@PER-CS2.0:risky`, `@PSR12`, `@PHP84Migration`, `@PHP83Migration`, `@PhpCsFixer:risky`.
- `native_function_invocation` is set to `strict` with scope `all` — prefix every PHP built-in call with `\` (e.g. `\sprintf`, `\is_array`, `\headers_sent`). Same for native constants (`\PHP_INT_MAX`, etc.) via `native_constant_invocation`.

## Out of scope (see TODO.md)

Console apps, Closure/string/int returns from the index callable, populating superglobals, and ReactPHP/Swoole/RoadRunner compatibility are explicit non-goals. If a feature request matches one of these, point at TODO.md rather than implementing.
