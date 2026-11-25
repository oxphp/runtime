# TODO — oxphp/runtime

Deferred items, tracked so they don't get lost during v1 work.
Anything in this file is **explicitly out of v1 scope**.

## v1.1 candidates

- [ ] `OxPHP\Runtime\Context` wrapper exposing `finishEarly()`, `isStreaming()`,
      `flush()`, `requestId()`, `workerId()` — public, stable surface around
      `oxphp_finish_request()` / `oxphp_is_streaming()` / `oxphp_stream_flush()`.
      Decide shape after real-world feedback on direct function usage.
- [ ] Integration test suite — boot a real OxPHP server from the sibling
      workspace, exercise Symfony / Laravel / PSR-15 apps in both traditional
      and worker mode. Dockerised.
- [ ] CI matrix on GitHub Actions: PHP 8.4 + 8.5, Symfony ^6.4/^7.x,
      Laravel ^11/^12, nyholm/guzzle/httpsoft/laminas as PSR-17 factories.
- [ ] APM integration helper: auto-record a span around each request in the
      worker loop when `OTEL_APM_ENABLED=true`, using `oxphp_apm_*()`.
      Opt-in via runtime option.
- [ ] `oxphp_async_await_all()` inside the Laravel `terminate()` phase:
      allow registered async jobs to be awaited after the response is sent.
- [ ] Ask OxPHP (issue #14 follow-up) for a way to abort a response mid-body,
      such as resetting the stream, so a response cut short by a failure isn't
      taken for a complete one; and for whether its stream headers are out,
      which `headers_sent()` misses after a flush with nothing written. The
      fallback 500 would use both.
- [ ] Stream every `StreamedResponse` (issue #17). Once OxPHP has a call that
      turns stream mode on without sending the headers (asked for), make it in
      `ResponseEmitter::emitCallback()` before the callback, if the function
      exists. The headers then go out with the first byte, and a callback that
      throws before writing still gets a 500. Then drop the README's advice to
      call `oxphp_stream_flush()` in the callback. Check the graceful shutdown
      first. OxPHP ends a suspended request in stream mode as soon as it starts
      to drain, so a callback waiting before its first write (on the database,
      say) would lose the drain window that an ordinary response gets.

## v2 candidates

- [ ] Split `LaravelRunner` into a standalone `oxphp/runtime-laravel` package
      if Laravel-specific resetters (scoped bindings, DB connections, session,
      queue state) grow beyond a few hundred lines.
- [ ] Optional `oxphp/runtime-nyholm` and `oxphp/runtime-httpsoft` wrappers
      that pin a PSR-17 factory — mirrors the `php-runtime/*` naming
      convention for users who prefer explicit packages over discovery.
- [ ] Streaming-aware emit for `Symfony\Component\HttpFoundation\StreamedJsonResponse`
      without a custom output handler — wait for upstream API stabilisation.

## Non-goals (will not implement)

- Console applications. OxPHP server is HTTP-only; console code runs under
  stock PHP CLI SAPI without this runtime.
- Closure / string / int return types from the index.php callable. Too
  ambiguous for a worker-mode environment; if a user returns one of these,
  we throw `LogicException` with guidance to wrap it in a PSR-15 handler
  or HttpFoundation Response.
- Global superglobal population when `SUPERGLOBALS_ENABLED=false`. The
  runtime reads request data via `oxphp_http_request()`, except uploaded
  files: it reads those from `$_FILES`, which OxPHP builds even with the
  flag off, because the request API's `files()` has no form field names.
  User code that still relies on `$_SERVER`/`$_GET`/`$_POST` must enable the
  server-side flag itself.
- ReactPHP / Swoole / RoadRunner compatibility. Those live in `php-runtime/*`.
