# oxphp/runtime

OxPHP runtime adapter — run **Symfony**, **Laravel**, **Yii 3** and **PSR-15**
applications on the [OxPHP](https://github.com/oxphp/oxphp) server in both
traditional and worker mode.

Extends [`symfony/runtime`](https://github.com/symfony/runtime). Zero hard
dependencies on any framework or PSR-7 implementation — everything is
auto-detected when the container is resolved.

## Requirements

- PHP **8.4+**
- OxPHP server (for production use; the runtime transparently falls back to
  `symfony/runtime` defaults when not running under the OxPHP SAPI).
  Worker mode needs OxPHP **0.11.0+**: older servers ignore the response
  status in worker mode and answer every request `200` (redirects and 404s
  included), and drop the `.env` values the boot wrote into `$_ENV`
  part-way through a request — a Symfony app then fails every `%env()%`
  lookup with `EnvNotFoundException`. Traditional mode works on any version.
  Requests that overlap on one worker need OxPHP **0.16.0+**: on 0.15.0, a
  request that starts while others are in flight inherits their headers-sent
  state, so its own headers fail.
- `symfony/runtime` `^6.4 || ^7.0 || ^8.0`

## Installation

```bash
composer require oxphp/runtime
```

Then set the runtime in `.env`:

```dotenv
APP_RUNTIME=OxPHP\Runtime\Runtime
```

Your `public/index.php` stays as it would be under `symfony/runtime`:

```php
<?php
use App\Kernel;

require_once \dirname(__DIR__).'/vendor/autoload_runtime.php';

return fn(array $context) => new Kernel($context['APP_ENV'], (bool)$context['APP_DEBUG']);
```

## Supported application types

| Return type from `index.php`                             | Runner                         |
|----------------------------------------------------------|--------------------------------|
| `Psr\Http\Server\RequestHandlerInterface`                | `Psr15Runner`                  |
| `Illuminate\Contracts\Http\Kernel`                       | `LaravelRunner`                |
| `Symfony\Component\HttpKernel\HttpKernelInterface`       | `HttpKernelRunner`             |
| `Symfony\Component\HttpFoundation\Response`              | `HttpFoundationResponseRunner` |
| `Psr\Http\Message\ResponseInterface`                     | `Psr7ResponseRunner`           |

Any other type throws `LogicException`. Console applications are out of
scope — run them under the stock PHP CLI SAPI.

Yii 3 web applications don't return anything from `index.php`; they use
`OxPHPApplicationRunner` instead of `symfony/runtime` — see [Yii 3](#yii-3).

## Traditional vs worker mode

Mode is auto-detected via `oxphp_is_worker()`. Nothing to configure on
the runtime side. In worker mode:

- Your application boots **once** per worker thread.
- `oxphp_worker()` drives the loop internally; the runtime wraps each
  request in `try/catch(\Throwable)` so a broken handler cannot poison
  the worker.
- Between requests, OxPHP performs a **soft reset** (output buffers,
  headers, superglobals). Framework-specific state you want to reset on
  top of that goes through the resetter chain.
- The `$_SERVER` entries written while the worker booted — `.env`
  variables, `APP_ENV`, `APP_DEBUG` and anything your `index.php`
  callable sets — are put back into every request's `$_SERVER`, never
  overriding a value OxPHP set for that request. `$_ENV` needs no help:
  OxPHP keeps it for the life of the worker.

## Resetters

Register custom resetters via `APP_RUNTIME_OPTIONS`:

```dotenv
APP_RUNTIME_OPTIONS="{\"resetters\":[\"App\\\\Runtime\\\\EntityManagerResetter\"]}"
```

Your class implements `OxPHP\Runtime\Resetter\ResetterInterface`:

```php
use OxPHP\Runtime\Resetter\ResetterInterface;

final class EntityManagerResetter implements ResetterInterface
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function reset(): void
    {
        $this->em->clear();
    }
}
```

Symfony users need no registration: after every worker request the runtime
resets the container, which calls `reset()` on every built `ResetInterface`
service and drops all service instances, so each request starts from a fresh
container. That teardown is replaced when
[`OxPHPBundle`](#overlapping-requests-symfony) is installed.

## Overlapping requests (Symfony)

OxPHP can run several requests at once on one worker. Each request runs in
its own fiber, and a request that waits — in `oxphp_sleep()`, in a hooked
stream read (`RUNTIME_HOOKS`), or in an open SSE stream — lets another
request run and finish on the same worker. Symfony keeps its per-request
state in services shared by the whole worker, so overlapping requests
corrupt each other: `app.request` is `null` in a template, a page renders
in another request's language, a request reads the security token another
request put in token storage.

`OxPHPBundle` gives each request its own copy of that state. It is opt-in;
without it, behaviour is unchanged apart from a log hint (see below). Add one
line to `config/bundles.php`:

```php
return [
    // ...
    OxPHP\Runtime\Symfony\OxPHPBundle::class => ['all' => true],
];
```

Outside OxPHP worker mode (PHP-FPM, the built-in server, console commands,
tests, OxPHP traditional mode) every service behaves exactly like the stock
one, so it is safe to enable the bundle in every environment.

### The container is no longer rebuilt

With the bundle installed, the Symfony container is **no longer rebuilt after
every worker request**. That teardown is what pulled services out from under
requests still in flight. Symfony's own `services_resetter` resets the
`kernel.reset` services instead — the same contract as Symfony's other
long-running runtimes.

A service that keeps per-request state must therefore do one of these, or
its state carries over to the next request:

- implement `Symfony\Contracts\Service\ResetInterface` (autoconfigured as
  `kernel.reset`);
- be tagged `kernel.reset` with the `method` to call (Symfony rejects the tag
  without one);
- be reset by one of your own [resetters](#resetters).

```yaml
# config/services.yaml
services:
    App\Cart\CurrentCart:
        tags: [{ name: kernel.reset, method: clear }]
```

Shared `kernel.reset` services are reset only once the worker is idle:
Symfony calls the resetter when a request starts, and the runtime skips that
call while another request is in flight. Under continuous overlap (an SSE
stream that never closes) they are not reset until a request starts on an
idle worker.

A request that ends without running its `finally` blocks (a fatal error, a
time limit, `exit()`, a server shutdown or worker recycle, or a client gone
mid-stream, see [Streaming](#streaming)) is still closed at its end by a
shutdown function the runtime registers. If it ended inside the kernel
(not in its streamed body), Symfony stops resetting services for the rest of
the worker's life, so from then on the runtime resets the `kernel.reset`
services itself at the end of every request on an idle worker. Either way,
each request is reset once.

### What is isolated

Each request sees only its own:

- request stack (`getMainRequest()`, `getCurrentRequest()`, `app.request`);
- router context used to generate URLs (base URL, host, scheme, `_locale`);
- translator locale and locale switcher locale;
- security token in token storage (`security.token_storage`);
- session usage tracking, so a stateless request overlapping a logged-in one
  doesn't mark its session as used;
- current firewall behind `logout_path()` and `logout_url()`.

A login still saves its token to its own session when another request
finishes first.

### What is not isolated

- Doctrine's `EntityManager` (one unit of work per worker).
- Monolog buffering handlers.
- `slugger` and every other `kernel.locale_aware` service besides the
  translator and the locale switcher.
- The ICU default locale (`\Locale::getDefault()`), which the locale switcher
  sets, on OxPHP before 0.12.0. From 0.12.0 OxPHP keeps it per request, as it
  keeps every ini directive PHP stores with a standard setter.
- Your own services and singletons.
- The authorization checker's own stacks, shared as in stock Symfony. While
  a check inside `isGrantedForUser()` waits (a voter in a hooked database or
  HTTP call), another request's `isGranted()` is decided for that user rather
  than for its own token; and while any check waits, other requests' checks
  record their votes and decision reasons into the waiting check's access
  decision. Isolating them is a planned follow-up.
- User resetters: they still run after every request, even while other
  requests are in flight, so one that clears shared state (for example
  `EntityManager::clear()`) clears it under requests that are still running.

A fiber your own code creates inside a request sees no request and no
token: its `getCurrentRequest()` is `null`, and what it stores stays its own
and that request's. The same goes for the destructors PHP's garbage collector
runs during a request, which PHP 8.4+ runs in a fiber of their own. Pass the
data in as a value instead. A request's shutdown functions see its state, as
in stock Symfony.

### Requirements

- `symfony/http-kernel` **7.3+**. On older versions the bundle does nothing
  and records why (see below).
- OxPHP **0.15.0+** recommended. Before 0.15.0, a fatal error inside a fiber
  can make `Fiber::getCurrent()` return `null` for later requests on that
  worker. The runtime then can't tell overlapping requests apart: for the
  rest of that worker's life it goes back to rebuilding the container after
  every request, and logs the third hint below.

### When the bundle can't isolate

The bundle is all-or-nothing. If a service it would replace can't be
isolated safely (a custom class, an alias, a Symfony version it doesn't
support), it changes nothing and the container keeps being rebuilt after
every request, as without the bundle.

Check what it did with `bin/console debug:container --parameter=oxphp_runtime.fiber_isolation`:
`true` means isolation is installed, `false` means it was withheld and
`oxphp_runtime.fiber_isolation_reason` says why.

Three hints go to the server log (`error_log()`), each at most once per
worker, and all start with `OxPHP runtime:`:

- `requests overlapped on this worker and share Symfony's request state` —
  requests overlapped and the bundle isn't registered. Add the line to
  `config/bundles.php`.
- `requests overlapped on this worker, but OxPHP\Runtime\Symfony\OxPHPBundle
  could not isolate them: <reason>` — the bundle is registered but was
  withheld. Remove the cause named in the reason (undo the custom class or
  alias, or upgrade `symfony/http-kernel` to 7.3+). Until then overlapping
  requests still share state.
- `a request on this worker ran outside any fiber` — the degraded worker
  described above. Upgrade OxPHP to 0.15 or later and restart the worker.

The first two appear only when a request ends while another is in flight, so
an app that never overlaps never sees them.

## PSR-17 factory

Used only by PSR-15 and bare PSR-7 Response paths. Auto-detected in this
order:

1. `Nyholm\Psr7\Factory\Psr17Factory` (recommended)
2. `GuzzleHttp\Psr7\HttpFactory`
3. `HttpSoft\Message\ServerRequestFactory` (+ siblings)
4. `Laminas\Diactoros\ServerRequestFactory` (+ siblings)

Override via `APP_RUNTIME_OPTIONS`:

```dotenv
APP_RUNTIME_OPTIONS="{\"psr17_factory\":\"My\\\\Factory\"}"
```

## Streaming

`StreamedResponse`, `StreamedJsonResponse`, and PSR-7 bodies larger than
3 MiB or of unknown size are streamed chunk-by-chunk — ideal for SSE and large
payloads.

Streamed callbacks are driven **live**: every `echo` inside a
`StreamedResponse` callback goes to the wire as soon as it happens —
chunks are never collected and replayed after the callback returns. The
runtime enables PHP's implicit flush while driving the callback, so the
engine pushes each chunk through the OxPHP SAPI's flush hook on write;
the callback does not need to call `flush()` itself. A PSR-7 body that
reports a size up to 3 MiB, the most OxPHP compresses, is read before the
headers go out, 8 KiB at a time and never more than 3 MiB plus one byte. If it
ends within 3 MiB, it is sent whole without a flush, so the server can
compress it. If not (a pipe or a socket reports size 0), what was read is the
first chunk and the rest follows. A body that reports more than 3 MiB, or no
size, is flushed per 8 KiB chunk via `oxphp_stream_flush()` from the first
byte, and OxPHP never compresses a streamed body. An exception thrown
mid-stream closes the runtime's output buffers cleanly (bytes already
streamed stay sent, partial buffers are discarded) and never poisons the
worker. If nothing had been written yet, the client gets a plain-text 500.
After that the status can't change: the response ends where it failed,
nothing is added to it, and the exception is logged. OxPHP still ends
such a response cleanly, so the client can't tell it from a complete one.
One exception: a
callback that calls `oxphp_stream_flush()` (or `flush()` in an SSE
response) before its first write has already sent the headers, which PHP
doesn't track, so a throw then sends the 500's text as the body of the 200.

When a client disconnects mid-stream, OxPHP by default stops the request
inside the write that finds it gone, and every `finally` the callback was in
is skipped. A long stream (SSE) that has to clean up calls
`ignore_user_abort(true)`: the write then returns and `connection_aborted()`
reports the disconnect. The loop must end on that check, since nothing else
stops the request any more:

```php
return new StreamedResponse(function (): void {
    \set_time_limit(0);
    \ignore_user_abort(true);

    try {
        while (!\connection_aborted()) {
            echo 'data: ', \json_encode(['ts' => \time()]), "\n\n";
            \oxphp_sleep(1.0);
        }
    } finally {
        // runs on a disconnect too
    }
}, 200, ['Content-Type' => 'text/event-stream']);
```

For "respond early, keep working" patterns use `oxphp_finish_request()`
directly in your controller:

```php
$response = new JsonResponse(['status' => 'accepted']);
// ... after sending ...
\oxphp_finish_request();
// background work continues; the client already got the response
```

## Yii 3

### Setup

Install the runtime. It depends on `symfony/runtime`, whose Composer plugin the
Yii runner doesn't use. `yiisoft/app` lists the plugins it allows, so Composer
asks about this one, and fails when it can't ask (`--no-interaction`, CI,
Docker builds). Turn the plugin off first:

```bash
composer config allow-plugins.symfony/runtime false
composer require oxphp/runtime
```

In `yiisoft/app`'s `public/index.php`, replace `HttpApplicationRunner` with
`OxPHPApplicationRunner`, and list the services the workers may share:

```php
use OxPHP\Runtime\Yii\OxPHPApplicationRunner;
use Psr\SimpleCache\CacheInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

$runner = new OxPHPApplicationRunner(
    rootPath: $root,
    debug: Environment::appDebug(),
    checkEvents: Environment::appDebug(),
    environment: Environment::appEnv(),
    temporaryErrorHandler: new ErrorHandler(/* as before */),
    sharedServices: [ConnectionInterface::class, CacheInterface::class],
);
$runner->run();
```

- The constructor takes `HttpApplicationRunner`'s arguments, except the
  deprecated ones (`logger`, `bufferSize` and the three `use*Middleware`
  flags), plus `sharedServices`.
- Outside OxPHP (PHP-FPM, `php -S`, CLI), `run()` is `HttpApplicationRunner`
  built from the same arguments. Keep `yiisoft/yii-runner-http` installed: it
  is that fallback, and it brings the packages the runner uses under OxPHP.
- It doesn't go through `symfony/runtime`: no `APP_RUNTIME`, no
  `autoload_runtime.php`.
- Pass a custom config with `withConfig()`. `withContainer()` throws under
  OxPHP, since every request's container is built from the config. So does
  calling `getContainer()` before `run()`: it keeps the container it builds
  where `withContainer()` puts one.
- A given `emitter` sends the responses under OxPHP too; without one, the
  runtime's own emitter sends the body as described in [Streaming](#streaming).

### Per-request isolation

OxPHP runs overlapping requests on one worker, so a container shared between
them lets one request's `CurrentRoute`, session, translator locale or view
state leak into another (or makes the second request fail). Every request
therefore gets a new container, which builds its own instance of every service
not listed in `sharedServices`. An object that already exists when the config
is read is the exception: every request shares it (see
[What the guard can't see](#what-the-guard-cant-see)). The container is dropped
when the request ends; it is never reused and never reset. On `yiisoft/app`'s
home page that costs roughly 0.6–0.7 ms per request, in a one-worker benchmark
on a real server. Overlapping requests need OxPHP 0.16.0 or later (see
[Requirements](#requirements)).

### Lifecycle

- `ApplicationStartup` and `ApplicationShutdown` fire once per worker, from a
  boot container. Requests never see that container's non-shared services,
  so what those listeners do to them doesn't reach any request.
- The bootstrap callbacks never run against the boot container, so those
  listeners don't see what the callbacks set up. `HttpApplicationRunner` runs
  bootstrap first, then `ApplicationStartup`, against its one container.
- Bootstrap callbacks (the `bootstrap-web` group) run per request, against
  that request's container, unlike Yii's FrankenPHP runner, which runs them
  once per worker. An entry that isn't callable fails the worker at boot.
- `AfterEmit` fires once per request, after the response is sent.

### Rules for shared services

The listed services are built once, at worker boot. A listed id that has a
`reset` definition, or that depends on a service that has one (such as
`CurrentRoute`), fails the boot. The message names the listed id, and the
per-request service when it is a dependency.

- Listed services live for the worker's life and are never reset.
- They see every request that overlaps, so any PHP state inside them is
  shared between those requests.
- List every id your code asks for. An id that isn't listed, such as the
  concrete class behind a listed interface, gets a fresh instance per
  request. An alias of a listed id gets the shared instance, whether it is a
  class name (`ConnectionInterface::class => Connection::class`) or
  `Reference::to()`.
- Key a shared service's provider extensions on the listed id itself. An
  extension keyed on an alias of a listed id would run again in every
  request, against the shared instance, so it fails the boot. The message
  names the alias and the listed id. Key the extension on the listed id, or
  list the alias too (a provider you can't change): then the extension runs
  once, at boot.
- A service that keeps a container or an `Injector` can't be shared. That
  includes the event dispatcher, `Application` and middleware dispatchers.
  Listing one fails the boot, either because it holds a container or because
  it depends on a per-request service; the message says which.

### What the guard can't see

- State hidden inside a shared service, such as a DB wrapper's transaction
  nesting level. OxPHP keeps a shared connection safe at the protocol level,
  but not the PHP object around it.
- Services from delegate containers (the `di-delegates-web` group). A shared
  service that depends on a per-request service from a delegate passes boot.
- Tags. Listing a `tag@…` id doesn't share its services; each request still
  builds them. List the member services instead.
- Aliases made with a closure, such as
  `ConnectionInterface::class => fn (Connection $c) => $c`. The guard follows
  only class-name and `Reference::to()` aliases, so an extension keyed on a
  closure alias passes boot and runs in every request.
- Objects created when the config is read: `new Foo()` as a definition, as a
  constructor argument, or captured with `use` by a definition closure. Each
  exists once per worker and is shared by every request, listed or not. Define
  per-request services as class names, arrays, or closures that create the
  object inside the closure.
- Static and global state, including anything a bootstrap callback stores in
  a static. Overlapping requests share it. `yiisoft/widget`'s bootstrap
  callback, for one, keeps a factory over the request's container in
  `WidgetFactory`, so a widget built through it resolves its dependencies
  from the container of the request that bootstrapped most recently, which
  may be another request's. That affects the packages built on it, such as
  `yiisoft/form`, `yiisoft/yii-widgets`, `yiisoft/yii-dataview` and
  `yiisoft/yii-bootstrap5`. Pass per-request dependencies to `::widget()` as
  constructor arguments:
  `MyWidget::widget(['currentRoute' => $currentRoute])`.

### Requests and environment

- Request data comes from OxPHP, not the superglobals, except uploads, which
  are read from `$_FILES`. Both work with `SUPERGLOBALS_ENABLED=false`.
- Server params hold what OxPHP puts in `$_SERVER` for the request, except
  what its request API has no source for: `SCRIPT_NAME`, `SCRIPT_FILENAME`,
  `PHP_SELF`, `DOCUMENT_ROOT`, `DOCUMENT_URI`, `PATH_INFO` (set for a URL such
  as `/index.php/news`), `REMOTE_PORT`, `GATEWAY_INTERFACE`, the tracing keys
  `OXPHP_TRACE_ID`, `OXPHP_SPAN_ID` and `OXPHP_PARENT_SPAN_ID`, the deny-fallback
  keys `OXPHP_DENIED_PATH` and `OXPHP_DENIED_PATTERN`, and the process
  environment. So `Subfolder` middleware can't detect a prefix; for an app that
  isn't at the web root, give it `prefix`.
- Read the environment from `$_ENV` or `getenv()`, not `$_SERVER`: OxPHP
  rebuilds `$_SERVER` for every request.

### Tested versions

Composer enforces a range only for the packages whose internals the runner
relies on: `yiisoft/yii-runner` 2.2 up to 3.0, `yiisoft/yii-runner-http` 3.2 up
to 4.0 and `yiisoft/di` 1.4 up to 2.0 (each upper bound excluded).

Tested with `yiisoft/yii-runner` 2.2, `yiisoft/yii-runner-http` 3.2,
`yiisoft/di` 1.4, `yiisoft/yii-http` 1.1, `yiisoft/config` 1.6,
`yiisoft/definitions` 3.4 and `yiisoft/error-handler` 4.3. The last four aren't
enforced: they're general-purpose libraries, and a conflict on them would also
block an older version in an app that doesn't use the Yii runner.

## License

MIT
