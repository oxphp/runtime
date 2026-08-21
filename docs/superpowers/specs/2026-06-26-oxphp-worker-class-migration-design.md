# Migration to `OxPHP\Server\Worker` (with function fallback)

**Date:** 2026-06-26
**Status:** Approved (design)

## Background

The `oxphp_sapi` extension (v0.9.0) added a unified runtime handle, the
`OxPHP\Server\Worker` class, alongside the existing free `oxphp_*()` functions.

Verified against the authoritative source `oxphp.stub.php` and the docs
(`functions.md`, `worker-class.md`):

- **Removed:** only `oxphp_request_heartbeat($time)` (→ `set_time_limit()`).
  This runtime does **not** use it — no impact.
- **Deprecated:** *nothing the runtime uses.* The docs state explicitly that the
  legacy free functions "remain available … they are not deprecated." New code
  should *prefer* the class API.
- **Added:** the `OxPHP\Server\Worker` class.

Of the functions this runtime calls, only two have class equivalents:

| Legacy function    | Class API                          |
|--------------------|------------------------------------|
| `oxphp_is_worker()`| `Worker::isWorkerMode()`           |
| `oxphp_worker($h)` | `Worker::current()->serve($h)`     |

The remaining functions used by the runtime (`oxphp_stream_flush`,
`oxphp_finish_request`, `oxphp_http_request`, `oxphp_request_id`,
`oxphp_server_info`, `oxphp_is_streaming`) have **no** class analog and are left
untouched.

## Goal

Prefer the class API for the two worker-mode equivalents, while transparently
falling back to the free functions when `OxPHP\Server\Worker` is absent (older
server). This is an internal mechanism swap — **runtime behavior does not
change**.

Decisions taken during brainstorming:
- **Compatibility:** fallback to free functions when the class is absent
  (not a hard migration). Consistent with the existing load-bearing fallback
  philosophy in `Runtime::getRunner()`.
- **Scope:** equivalents only (YAGNI). Do **not** adopt new capabilities
  (`scheduleExit`, `requestCount`, `rss`, `memoryUsage`).
- **Encapsulation:** wrap the worker-loop entry in a Bridge method `serve()`;
  the runner delegates to it.
- **Testability:** constructor seam on the Bridge to exercise both branches.

## Components

### 1. `Internal\Bridge\OxPHP` — single point of encapsulation

The bridge becomes the only place aware of both forms of the worker API. (Today
`oxphp_worker()` is called directly from the runner, which contradicts the
invariant stated in CLAUDE.md — "add a method here rather than scattering global
function calls across runners." This migration also fixes that.)

- Constructor seam:
  `public function __construct(?bool $useWorkerClass = null)`.
  `null` = autodetect via `class_exists(\OxPHP\Server\Worker::class)`,
  resolved once and stored in a private `bool` property. The runtime always uses
  autodetect; tests pass `true`/`false` explicitly.
- `isWorker(): bool`
  → `$useWorkerClass ? \OxPHP\Server\Worker::isWorkerMode() : \oxphp_is_worker()`.
- New `serve(callable $handler): void`
  → `$useWorkerClass ? \OxPHP\Server\Worker::current()->serve($handler) : \oxphp_worker($handler)`.
  The `bool` return of `oxphp_worker()` is discarded (the runner never used it).

The other bridge methods (`isStreaming`, `streamFlush`, `finishRequest`,
`currentRequest`) are unchanged.

### 2. `Internal\Runner\AbstractHttpRunner`

`runWorker()` replaces the direct `\oxphp_worker(fn)` call with
`$this->bridge->serve(fn)`. The handler closure is unchanged: the
`try/catch(\Throwable)` + `finally { $this->resetters->reset(); }` invariant
("a broken handler must never poison the worker") is preserved verbatim.

## Semantic notes (accounted for)

- `Worker::serve()` throws `OxPHP\Server\Exception\InvalidServeContextException`
  outside worker mode, whereas `oxphp_worker()` emits `E_WARNING` and returns
  `false`. The runner only calls `serve()` after `isWorker() === true`, so the
  exception is unreachable in production. No extra handling is added.
- `Runtime::getRunner()` and its load-bearing detection
  (`\PHP_SAPI === 'cli' || !\function_exists('oxphp_server_info')`) are **not**
  modified — `oxphp_server_info` is neither removed nor deprecated.

## Error handling

Unchanged. The fallback is a plain `class_exists` check, no try/catch. When the
extension is entirely absent (local dev / CI), `Runtime` falls back to
`parent::getRunner()` earlier and the bridge is never constructed.

## Testing

Both branches (class and functions) must be covered.

- **Bridge unit test:** construct `OxPHP(true)` and `OxPHP(false)`; assert
  `isWorker()` and `serve()` route to the class path and the function path
  respectively, both backed by `OxPHPHarness`.
- **Polyfill:** add `OxPHP\Server\Worker` and
  `OxPHP\Server\Exception\InvalidServeContextException` to the test polyfill,
  backed by a per-process singleton over `OxPHPHarness`:
  - `Worker::isWorkerMode()` → `harness->isWorker()`
  - `Worker::current()->serve($h)` → throws `InvalidServeContextException` when
    `!harness->isWorker()`, otherwise `harness->driveWorker($h)` (returns void).
  The free-function polyfills in `bootstrap.php` remain for the fallback branch.
- **Existing runner tests** (`setWorker(true)` → drive worker) keep working via
  autodetect, since the polyfill declares the `Worker` class.
- **Stub sync:** `Worker` (+ exception) added to the polyfill. The removed
  `oxphp_request_heartbeat` is not present in the polyfill — nothing to remove.

## Change surface

- `src/Internal/Bridge/OxPHP.php`
- `src/Internal/Runner/AbstractHttpRunner.php`
- `tests/polyfill/` (new file for the `Worker` class + exception)
- `tests/bootstrap.php` (wire the Worker polyfill alongside the function stubs)
- `tests/Stub/OxPHPHarness.php` (only if a hook is needed for the serve-context
  exception)
- New/updated bridge test covering both branches

## Out of scope

- Adopting `scheduleExit`/`requestCount`/`rss`/`memoryUsage` (YAGNI).
- Changing any non-worker `oxphp_*` call.
- Changing `Runtime::getRunner()` SAPI detection.
