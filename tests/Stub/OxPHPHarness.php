<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub;

use OxPHP\Http\RequestInterface;

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

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public static function reset(): void
    {
        self::$instance = new self();
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

        foreach ($this->queue as $req) {
            $this->current = $req;
            \ob_start();
            try {
                $handler();
            } finally {
                $this->outputs[] = (string) \ob_get_clean();
                $this->statuses[] = (int) \http_response_code();
                // Simulate server-side soft reset: fresh status for the next
                // iteration so leftover 4xx/5xx codes don't leak.
                \http_response_code(200);
                $this->resetCount++;
            }
        }
        $this->queue = [];
        $this->current = null;
        return true;
    }
}
