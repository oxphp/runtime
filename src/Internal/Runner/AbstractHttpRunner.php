<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Runner;

use OxPHP\Http\RequestInterface as OxRequest;
use OxPHP\Runtime\Internal\Bridge\OxPHP;
use OxPHP\Runtime\Internal\Reset\ResetterChain;
use Symfony\Component\Runtime\RunnerInterface;

/**
 * @internal Common HTTP runner: selects between one-shot and worker-loop
 * execution based on oxphp_is_worker(). Concrete subclasses implement
 * handleRequest() — request ingestion and response emission for their
 * specific application type.
 */
abstract class AbstractHttpRunner implements RunnerInterface
{
    public function __construct(
        protected readonly OxPHP $bridge,
        protected readonly ResetterChain $resetters,
    ) {}

    public function run(): int
    {
        return $this->bridge->isWorker()
            ? $this->runWorker()
            : $this->runOnce();
    }

    abstract protected function handleRequest(OxRequest $req): void;

    /** Optional hook — subclasses can boot their kernel here. */
    protected function onWorkerBoot(): void {}

    /** Optional hook — runs after oxphp_worker() returns (graceful shutdown). */
    protected function onWorkerShutdown(): void {}

    private function runOnce(): int
    {
        try {
            $this->handleRequest($this->bridge->currentRequest());
            return 0;
        } catch (\Throwable $e) {
            $this->emitFallback500($e);
            return 1;
        }
    }

    private function runWorker(): int
    {
        $this->onWorkerBoot();

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

        $this->onWorkerShutdown();
        return 0;
    }

    private function emitFallback500(\Throwable $e): void
    {
        if (!\headers_sent()) {
            \http_response_code(500);
            \header('Content-Type: text/plain; charset=utf-8');
        }
        \error_log('OxPHP runtime unhandled exception: ' . $e);
        echo "Internal Server Error\n";
    }
}
