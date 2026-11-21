# oxphp/runtime

OxPHP runtime adapter — run **Symfony**, **Laravel** and **PSR-15** applications
on the [OxPHP](https://github.com/oxphp/oxphp) server in both traditional and
worker mode.

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
in another request's language, a request reads another request's security
token.

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
- be tagged `kernel.reset`;
- be reset by one of your own [resetters](#resetters).

Shared `kernel.reset` services are reset only once the worker is idle:
Symfony calls the resetter when a request starts, and the runtime skips that
call while another request is in flight. Under continuous overlap (an SSE
stream that never closes) they are not reset until a request starts on an
idle worker.

### What is isolated

Each request sees only its own:

- request stack (`getMainRequest()`, `getCurrentRequest()`, `app.request`);
- router context used to generate URLs (base URL, host, scheme, `_locale`);
- translator locale and locale switcher locale;
- security token;
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
- Your own services and singletons.
- User resetters: they still run after every request, even while other
  requests are in flight, so one that clears shared state (for example
  `EntityManager::clear()`) clears it under requests that are still running.

A fiber your own code creates inside a request sees no request and no
token: its `getCurrentRequest()` is `null`. Pass the data in as a value
instead.

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

`StreamedResponse`, `StreamedJsonResponse`, and PSR-7 bodies with
readable streams are streamed chunk-by-chunk — ideal for SSE and large
payloads.

Streamed callbacks are driven **live**: every `echo` inside a
`StreamedResponse` callback goes to the wire as soon as it happens —
chunks are never collected and replayed after the callback returns. The
runtime enables PHP's implicit flush while driving the callback, so the
engine pushes each chunk through the OxPHP SAPI's flush hook on write;
the callback does not need to call `flush()` itself. PSR-7 stream bodies
are flushed per chunk via `oxphp_stream_flush()`. An exception thrown
mid-stream closes the runtime's output buffers cleanly (bytes already
streamed stay sent, partial buffers are discarded) and never poisons the
worker.

For "respond early, keep working" patterns use `oxphp_finish_request()`
directly in your controller:

```php
$response = new JsonResponse(['status' => 'accepted']);
// ... after sending ...
\oxphp_finish_request();
// background work continues; the client already got the response
```

## License

MIT
