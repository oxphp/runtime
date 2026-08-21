# OxPHP\Server\Worker Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the runtime prefer the `OxPHP\Server\Worker` class for the two worker-mode equivalents (`isWorker`, worker loop) while transparently falling back to the free `oxphp_*()` functions when the class is absent.

**Architecture:** All knowledge of "class vs free function" lives in the `Internal\Bridge\OxPHP` bridge. The bridge resolves once (constructor) whether to use the class, via `class_exists(\OxPHP\Server\Worker::class)`, with a constructor seam (`?bool $useWorkerClass`) so tests can drive both branches. The worker-loop entry moves out of the runner into a new `OxPHP::serve()` method. Runtime behavior is unchanged.

**Tech Stack:** PHP 8.4, PHPUnit (bootstrap `tests/bootstrap.php`, harness `OxPHPHarness`), php-cs-fixer.

## Global Constraints

- PHP **8.4+**; `declare(strict_types=1);` in every file.
- `native_function_invocation` strict / scope all — prefix every PHP built-in with `\` (`\class_exists`, `\oxphp_worker`, …); native constants too (`\PHP_SAPI`).
- PHPUnit runs with `failOnWarning`, `failOnNotice`, `failOnRisky` all true — a warning is a failure.
- Only `Runtime` and `ResetterInterface` are public; everything under `Internal\` is `@internal`.
- Worker-mode invariant: a broken handler must never poison the worker — the `try/catch(\Throwable)` + `finally { resetters }` closure body stays intact.
- `Runtime::getRunner()` SAPI detection (`\PHP_SAPI === 'cli' || !\function_exists('oxphp_server_info')`) must NOT change.
- The test polyfill is the mirror of `oxphp.stub.php` — signatures must match the stub.

## File Structure

- `tests/polyfill/oxphp_server_worker.php` (Create) — polyfill for `OxPHP\Server\Worker` + `OxPHP\Server\Exception\InvalidServeContextException`, backed by `OxPHPHarness`.
- `tests/bootstrap.php` (Modify) — require the new polyfill when the real class is absent.
- `tests/Unit/Stub/WorkerPolyfillTest.php` (Create) — verify the polyfill routes to the harness and throws outside worker mode.
- `src/Internal/Bridge/OxPHP.php` (Modify) — constructor seam, dual-path `isWorker()`, new `serve()`.
- `tests/Unit/Internal/Bridge/OxPHPTest.php` (Modify) — cover both branches of `isWorker()` and `serve()`.
- `src/Internal/Runner/AbstractHttpRunner.php` (Modify) — delegate worker loop to `$this->bridge->serve()`.

---

### Task 1: Worker polyfill backed by the harness

**Files:**
- Create: `tests/polyfill/oxphp_server_worker.php`
- Modify: `tests/bootstrap.php`
- Test: `tests/Unit/Stub/WorkerPolyfillTest.php`

**Interfaces:**
- Consumes: `OxPHP\Runtime\Tests\Stub\OxPHPHarness::instance()` with existing methods `isWorker(): bool` and `driveWorker(callable): bool`.
- Produces:
  - `OxPHP\Server\Worker::current(): self`
  - `OxPHP\Server\Worker::isWorkerMode(): bool`
  - `OxPHP\Server\Worker->serve(callable $handler): void` (throws `OxPHP\Server\Exception\InvalidServeContextException` when not in worker mode)
  - `OxPHP\Server\Exception\InvalidServeContextException extends \RuntimeException`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Stub/WorkerPolyfillTest.php`:

```php
<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Stub;

use OxPHP\Runtime\Tests\Stub\FakeOxRequest;
use OxPHP\Runtime\Tests\Stub\OxPHPHarness;
use OxPHP\Server\Exception\InvalidServeContextException;
use OxPHP\Server\Worker;
use PHPUnit\Framework\TestCase;

final class WorkerPolyfillTest extends TestCase
{
    protected function setUp(): void
    {
        OxPHPHarness::reset();
    }

    public function test_current_returns_singleton(): void
    {
        self::assertSame(Worker::current(), Worker::current());
    }

    public function test_is_worker_mode_reflects_harness(): void
    {
        self::assertFalse(Worker::isWorkerMode());
        OxPHPHarness::instance()->setWorker(true);
        self::assertTrue(Worker::isWorkerMode());
    }

    public function test_serve_drives_handler_in_worker_mode(): void
    {
        OxPHPHarness::instance()->setWorker(true)->pushRequest(FakeOxRequest::get('/a'));
        $seen = 0;
        Worker::current()->serve(function () use (&$seen): void {
            $seen++;
        });
        self::assertSame(1, $seen);
    }

    public function test_serve_throws_outside_worker_mode(): void
    {
        OxPHPHarness::instance()->setWorker(false);
        $this->expectException(InvalidServeContextException::class);
        Worker::current()->serve(static fn (): null => null);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter WorkerPolyfillTest tests/Unit/Stub/WorkerPolyfillTest.php`
Expected: FAIL — `Class "OxPHP\Server\Worker" not found` (polyfill not declared yet).

- [ ] **Step 3: Create the polyfill**

Create `tests/polyfill/oxphp_server_worker.php`:

```php
<?php

declare(strict_types=1);

/**
 * Polyfill for the OxPHP\Server\Worker class provided by the oxphp_sapi PHP extension.
 *
 * Loaded only when the real extension is not present. The single source of truth
 * for these signatures is oxphp.stub.php in the oxphp server repository — keep
 * both files in sync. Only the members the runtime relies on are modelled:
 * current(), isWorkerMode(), serve(). Introspection accessors (id, requestCount,
 * rss, …) are intentionally omitted (YAGNI).
 */

namespace OxPHP\Server {

    use OxPHP\Runtime\Tests\Stub\OxPHPHarness;
    use OxPHP\Server\Exception\InvalidServeContextException;

    final class Worker
    {
        private static ?self $instance = null;

        public static function current(): self
        {
            return self::$instance ??= new self();
        }

        public static function isWorkerMode(): bool
        {
            return OxPHPHarness::instance()->isWorker();
        }

        public function serve(callable $handler): void
        {
            if (!OxPHPHarness::instance()->isWorker()) {
                throw new InvalidServeContextException('serve() called outside worker mode');
            }

            OxPHPHarness::instance()->driveWorker($handler);
        }
    }
}

namespace OxPHP\Server\Exception {

    final class InvalidServeContextException extends \RuntimeException {}
}
```

- [ ] **Step 4: Wire the polyfill into the bootstrap**

In `tests/bootstrap.php`, after the existing `OxPHP\Http` interface polyfill block (the `if (!\interface_exists(...)) { require ...; }`), add:

```php
// Declare the OxPHP\Server\Worker polyfill only when the real extension isn't loaded.
if (!\class_exists(\OxPHP\Server\Worker::class, false)) {
    require __DIR__ . '/polyfill/oxphp_server_worker.php';
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter WorkerPolyfillTest tests/Unit/Stub/WorkerPolyfillTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add tests/polyfill/oxphp_server_worker.php tests/bootstrap.php tests/Unit/Stub/WorkerPolyfillTest.php
git commit -m "test: add OxPHP\\Server\\Worker polyfill backed by harness"
```

---

### Task 2: Bridge dual-path `isWorker()` + `serve()`

**Files:**
- Modify: `src/Internal/Bridge/OxPHP.php`
- Test: `tests/Unit/Internal/Bridge/OxPHPTest.php`

**Interfaces:**
- Consumes: `OxPHP\Server\Worker::isWorkerMode()`, `OxPHP\Server\Worker::current()->serve()` (from Task 1 / the real extension); free functions `\oxphp_is_worker()`, `\oxphp_worker()`.
- Produces:
  - `OxPHP\Runtime\Internal\Bridge\OxPHP::__construct(?bool $useWorkerClass = null)` — `null` autodetects via `\class_exists(\OxPHP\Server\Worker::class)`.
  - `OxPHP::isWorker(): bool` (dual-path)
  - `OxPHP::serve(callable $handler): void` (dual-path; class path → `Worker::current()->serve()`, function path → `\oxphp_worker()` with the bool return discarded)

- [ ] **Step 1: Write the failing tests**

In `tests/Unit/Internal/Bridge/OxPHPTest.php`, add these methods to the class (keep the existing tests; the existing `test_is_worker_reflects_harness` continues to use `new OxPHP()` autodetect):

```php
    public function test_is_worker_via_class_path(): void
    {
        OxPHPHarness::instance()->setWorker(true);
        self::assertTrue(new OxPHP(true)->isWorker());
    }

    public function test_is_worker_via_function_path(): void
    {
        OxPHPHarness::instance()->setWorker(true);
        self::assertTrue(new OxPHP(false)->isWorker());
    }

    public function test_serve_drives_handler_via_class_path(): void
    {
        OxPHPHarness::instance()->setWorker(true)->pushRequest(FakeOxRequest::get('/a'));
        $seen = 0;
        new OxPHP(true)->serve(function () use (&$seen): void {
            $seen++;
        });
        self::assertSame(1, $seen);
    }

    public function test_serve_drives_handler_via_function_path(): void
    {
        OxPHPHarness::instance()->setWorker(true)->pushRequest(FakeOxRequest::get('/a'));
        $seen = 0;
        new OxPHP(false)->serve(function () use (&$seen): void {
            $seen++;
        });
        self::assertSame(1, $seen);
    }
```

`FakeOxRequest` is already imported in this file. No new `use` needed.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter OxPHPTest tests/Unit/Internal/Bridge/OxPHPTest.php`
Expected: FAIL — `OxPHP::__construct()` does not accept an argument / `serve()` undefined.

- [ ] **Step 3: Update the bridge**

Replace the contents of `src/Internal/Bridge/OxPHP.php` with:

```php
<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Bridge;

use OxPHP\Http\RequestInterface as OxRequest;
use OxPHP\Server\Worker;

/**
 * @internal Thin wrapper over the oxphp extension. Worker-mode entry points
 * prefer the OxPHP\Server\Worker class and fall back to the free oxphp_*()
 * functions when the class is unavailable (older server).
 */
final class OxPHP
{
    private readonly bool $useWorkerClass;

    /**
     * @param bool|null $useWorkerClass null autodetects the OxPHP\Server\Worker
     *        class; pass an explicit bool only in tests to drive each branch.
     */
    public function __construct(?bool $useWorkerClass = null)
    {
        $this->useWorkerClass = $useWorkerClass ?? \class_exists(Worker::class);
    }

    public function isWorker(): bool
    {
        return $this->useWorkerClass
            ? Worker::isWorkerMode()
            : \oxphp_is_worker();
    }

    /**
     * Enter the worker request-dispatch loop. Returns when the loop ends
     * (graceful shutdown). Only call after isWorker() is true.
     */
    public function serve(callable $handler): void
    {
        if ($this->useWorkerClass) {
            Worker::current()->serve($handler);

            return;
        }

        \oxphp_worker($handler);
    }

    public function isStreaming(): bool
    {
        return \oxphp_is_streaming();
    }
    public function streamFlush(): void
    {
        \oxphp_stream_flush();
    }
    public function finishRequest(): bool
    {
        return \oxphp_finish_request();
    }

    public function currentRequest(): OxRequest
    {
        return \oxphp_http_request();
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter OxPHPTest tests/Unit/Internal/Bridge/OxPHPTest.php`
Expected: PASS (existing + 4 new tests).

- [ ] **Step 5: Commit**

```bash
git add src/Internal/Bridge/OxPHP.php tests/Unit/Internal/Bridge/OxPHPTest.php
git commit -m "feat: prefer OxPHP\\Server\\Worker in bridge with function fallback"
```

---

### Task 3: Runner delegates the worker loop to the bridge

**Files:**
- Modify: `src/Internal/Runner/AbstractHttpRunner.php:51-70`
- Test: existing runner suite (`AbstractHttpRunnerTest`, `HttpKernelRunnerTest`, `LaravelRunnerTest`, `Psr15RunnerTest`)

**Interfaces:**
- Consumes: `OxPHP\Runtime\Internal\Bridge\OxPHP::serve(callable): void` (from Task 2).
- Produces: no new public surface; `runWorker()` internals change only.

- [ ] **Step 1: Replace the direct function call with the bridge method**

In `src/Internal/Runner/AbstractHttpRunner.php`, in `runWorker()`, change the worker-loop entry from:

```php
        // Single boot — oxphp_worker() loops internally. Each iteration gets
        // a fresh request via the SAPI soft reset; we catch Throwable so a
        // broken handler cannot poison the worker.
        \oxphp_worker(function (): void {
            try {
                $this->handleRequest($this->bridge->currentRequest());
            } catch (\Throwable $e) {
                $this->emitFallback500($e);
            } finally {
                $this->resetters->reset();
            }
        });
```

to:

```php
        // Single boot — the worker loops internally. Each iteration gets a fresh
        // request via the SAPI soft reset; we catch Throwable so a broken handler
        // cannot poison the worker. The bridge picks Worker::serve() or the
        // oxphp_worker() fallback.
        $this->bridge->serve(function (): void {
            try {
                $this->handleRequest($this->bridge->currentRequest());
            } catch (\Throwable $e) {
                $this->emitFallback500($e);
            } finally {
                $this->resetters->reset();
            }
        });
```

- [ ] **Step 2: Run the runner suite to verify it still passes**

Run: `vendor/bin/phpunit tests/Unit/Internal/Runner`
Expected: PASS (all runner tests green — the worker-driven tests now route through `OxPHP::serve()` → the polyfill `Worker`).

- [ ] **Step 3: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: PASS, no warnings/notices/risky (the config fails on any of those).

- [ ] **Step 4: Apply formatting**

Run: `vendor/bin/php-cs-fixer fix`
Then re-check: `vendor/bin/php-cs-fixer fix --dry-run --diff`
Expected: no diff remaining.

- [ ] **Step 5: Commit**

```bash
git add src/Internal/Runner/AbstractHttpRunner.php
git commit -m "refactor: route worker loop through OxPHP::serve()"
```

---

## Self-Review

**Spec coverage:**
- Bridge dual-path `isWorker()` + `serve()` with constructor seam → Task 2. ✓
- Runner delegates worker loop to bridge → Task 3. ✓
- `Worker` + `InvalidServeContextException` polyfill added, function polyfills retained → Task 1 (functions in `bootstrap.php` untouched). ✓
- Both branches tested → Task 1 (polyfill), Task 2 (`new OxPHP(true)`/`new OxPHP(false)`). ✓
- `oxphp_request_heartbeat` removal: not present in polyfill, nothing to remove → noted, no task needed. ✓
- `Runtime::getRunner()` untouched; non-worker `oxphp_*` calls untouched → no task touches them. ✓
- `OxPHPHarness` change "only if needed": the polyfill `serve()` checks `isWorker()` and throws itself, so the harness is NOT modified. ✓

**Placeholder scan:** No TBD/TODO; every code step shows full code. ✓

**Type consistency:** `useWorkerClass` (constructor param + private prop), `serve(callable): void`, `isWorker(): bool`, `Worker::isWorkerMode()`, `Worker::current()->serve()`, `InvalidServeContextException` — names consistent across Tasks 1–3. ✓
