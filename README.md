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
  `symfony/runtime` defaults when not running under the OxPHP SAPI)
- `symfony/runtime` `^6.4 || ^7.0`

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

Symfony users get the container's `services_resetter` (any service tagged
`kernel.reset`) wired up automatically — no registration needed.

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
readable streams are flushed chunk-by-chunk via `oxphp_stream_flush()` —
ideal for SSE and large payloads.

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
