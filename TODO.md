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
      fallback 500 would use both. OxPHP fixed the second after 0.16.0 (not
      released yet): a stream's first flush now sets `headers_sent()`.
- [ ] `oxphp_stream_start()` (issue #17). It was added to OxPHP after 0.16.0
      and isn't released yet. It puts a request in stream mode without
      sending anything, and the headers go out at the first flush. Once it
      ships, comment on #17 and change the README: a callback that writes as
      it goes calls it first, if the function exists, instead of calling
      `oxphp_stream_flush()` after its first write. Don't call it for every
      `StreamedResponse`. From the call on, a graceful shutdown ends the
      request as a stream instead of giving it the drain window, so only a
      response that is really written as it is produced (a CSV export) should
      opt in. If the runtime is to make the call itself, it should do so only
      for a response that asks for it, e.g. with `X-Accel-Buffering: no`. The
      same release makes `headers_sent()` true at a stream's first flush, so
      the README's caveat about flushing before the first write, and the
      matching note in `CLAUDE.md`, will apply to older versions only.

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
