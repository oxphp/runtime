<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub;

use OxPHP\Http\RequestInterface;
use OxPHP\Runtime\Internal\Fiber\RequestScopes;

final class OxPHPHarness
{
    private static ?self $instance = null;

    private bool $worker = false;
    private bool $streaming = false;
    /** @var list<RequestInterface> */
    private array $queue = [];
    private ?RequestInterface $current = null;
    private int $flushCount = 0;
    private int $finishCount = 0;
    private string $requestId = 'test-req-id';
    private int $workerId = 0;
    /** @var list<string> */
    private array $outputs = [];
    /** @var list<int> */
    private array $statuses = [];
    private int $resetCount = 0;
    /** @var array<string, mixed>|null */
    private ?array $requestServer = null;
    private bool $bailoutMode = false;
    private int $bailouts = 0;
    /** @var list<\Closure> shutdown callbacks of the iteration in progress; null between iterations */
    private ?array $shutdownCallbacks = null;
    /** @var list<\Fiber> requests that bailed out, kept so their `finally` blocks never run during the test */
    private array $parked = [];

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Starts over with a fresh harness. Requests parked by a bailout are released
     * first, while the registry still holds their state; see releaseParked().
     */
    public static function reset(): void
    {
        self::$instance?->releaseParked();
        self::$instance = new self();
        RequestScopes::resetForTests();
    }

    public function setWorker(bool $on): self
    {
        $this->worker = $on;
        return $this;
    }
    public function setStreaming(bool $on): self
    {
        $this->streaming = $on;
        return $this;
    }
    public function setRequestId(string $id): self
    {
        $this->requestId = $id;
        return $this;
    }
    public function setWorkerId(int $id): self
    {
        $this->workerId = $id;
        return $this;
    }

    /**
     * In bailout mode, an iteration whose handler suspends its fiber plays a
     * request OxPHP ended without unwinding it (a fatal error, a timeout,
     * `exit()`, a cancel, a client gone mid-stream): none of its `finally` blocks
     * run, its shutdown callbacks do, and the worker moves on to the next request.
     */
    public function setBailoutMode(bool $on): self
    {
        $this->bailoutMode = $on;
        return $this;
    }

    /**
     * Simulate the soft reset rebuilding $_SERVER: driveWorker() starts every
     * request with exactly this array, and puts the real $_SERVER back after.
     *
     * @param array<string, mixed> $server
     */
    public function setRequestServer(array $server): self
    {
        $this->requestServer = $server;
        return $this;
    }

    public function pushRequest(RequestInterface $req): self
    {
        $this->queue[] = $req;
        return $this;
    }

    public function setCurrentRequest(RequestInterface $req): self
    {
        $this->current = $req;
        return $this;
    }

    public function isWorker(): bool
    {
        return $this->worker;
    }
    public function isStreaming(): bool
    {
        return $this->streaming;
    }
    public function request(): RequestInterface
    {
        if ($this->current === null) {
            throw new \LogicException('No active request set in harness.');
        }
        return $this->current;
    }
    public function requestId(): string
    {
        return $this->requestId;
    }
    public function workerId(): int
    {
        return $this->workerId;
    }
    public function serverInfo(): array
    {
        return [
            'sapi' => 'oxphp',
            'version' => '0.0.0-test',
            'worker_id' => $this->workerId,
            'request_time' => 1_700_000_000.0,
        ];
    }

    public function recordFlush(): bool
    {
        $this->flushCount++;
        return true;
    }
    public function recordFinish(): bool
    {
        $this->finishCount++;
        return true;
    }
    public function recordReset(): void
    {
        $this->resetCount++;
    }

    /**
     * Backs the bridge's registerRequestShutdown() in tests (see bootstrap.php):
     * the callback runs at the end of the iteration in progress, after the
     * handler and its `finally` blocks, as OxPHP runs a request's own shutdown
     * functions.
     */
    public function registerShutdown(\Closure $callback): void
    {
        if ($this->shutdownCallbacks === null) {
            throw new \LogicException('registerShutdown() called outside a worker iteration');
        }

        $this->shutdownCallbacks[] = $callback;
    }

    public function flushCount(): int
    {
        return $this->flushCount;
    }
    public function finishCount(): int
    {
        return $this->finishCount;
    }
    public function resetCount(): int
    {
        return $this->resetCount;
    }

    /** Number of iterations that bailed out (see setBailoutMode()). */
    public function bailouts(): int
    {
        return $this->bailouts;
    }

    /** @return list<string> per-request captured stdout */
    public function capturedOutputs(): array
    {
        return $this->outputs;
    }

    /**
     * @return list<int> per-request HTTP status, read the way the OxPHP server
     *         reads it at request completion (SG(sapi_headers).http_response_code)
     */
    public function capturedStatuses(): array
    {
        return $this->statuses;
    }

    public function driveWorker(callable $handler): bool
    {
        // Each request starts at 200, as after the server's soft reset — a
        // status left over from earlier code must not leak into the first one.
        \http_response_code(200);

        $realServer = $_SERVER;
        try {
            foreach ($this->queue as $req) {
                $this->current = $req;
                if ($this->requestServer !== null) {
                    $_SERVER = $this->requestServer;
                }
                $this->shutdownCallbacks = [];
                $level = \ob_get_level();
                \ob_start();
                try {
                    // OxPHP runs every worker iteration inside a fiber of its
                    // own; mirror that so fiber-keyed code sees a current fiber.
                    $fiber = new \Fiber($handler);
                    $fiber->start();
                    if (!$fiber->isTerminated()) {
                        // Kept, so the request's finally blocks don't run when the
                        // fiber is destroyed; reset() releases it.
                        $this->parked[] = $fiber;
                        if (!$this->bailoutMode) {
                            throw new \LogicException('handler suspended outside a RequestFiber scenario');
                        }
                        ++$this->bailouts;
                    }
                    $this->runShutdownCallbacks();
                } finally {
                    $this->shutdownCallbacks = null;
                    // A request that bailed out can leave buffers of its own open;
                    // the end of the request flushes them into its output.
                    while (\ob_get_level() > $level + 1) {
                        \ob_end_flush();
                    }
                    $this->outputs[] = (string) \ob_get_clean();
                    $this->statuses[] = (int) \http_response_code();
                    // Simulate server-side soft reset: fresh status for the next
                    // iteration so leftover 4xx/5xx codes don't leak.
                    \http_response_code(200);
                    $this->resetCount++;
                }
            }
        } finally {
            $_SERVER = $realServer;
        }
        $this->queue = [];
        $this->current = null;
        return true;
    }

    private function runShutdownCallbacks(): void
    {
        // A callback may register another one, which then runs too, as in PHP.
        for ($i = 0; $i < \count($this->shutdownCallbacks ?? []); ++$i) {
            ($this->shutdownCallbacks[$i])();
        }
    }

    /**
     * Destroys the parked fibers. Destroying a suspended fiber unwinds it, so the
     * `finally` blocks of the request that bailed out run now: whatever they
     * print, log or throw is discarded, so nothing leaks into the next test.
     * Safe to call again; there is nothing left to release then.
     */
    private function releaseParked(): void
    {
        if ($this->parked === []) {
            return;
        }

        $previousLog = \ini_get('error_log');
        \ini_set('error_log', \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
        $level = \ob_get_level();
        \ob_start();

        try {
            while ($this->parked !== []) {
                $fiber = \array_pop($this->parked);

                try {
                    unset($fiber);
                    \gc_collect_cycles();
                } catch (\Throwable) {
                    // The request's own cleanup failed; it has nowhere to go.
                }
            }
        } finally {
            while (\ob_get_level() > $level) {
                \ob_end_clean();
            }
            \ini_set('error_log', $previousLog === false ? '' : $previousLog);
        }
    }
}
