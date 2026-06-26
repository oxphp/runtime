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
