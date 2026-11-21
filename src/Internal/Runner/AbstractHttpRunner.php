<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Runner;

use OxPHP\Http\RequestInterface as OxRequest;
use OxPHP\Runtime\Internal\Bridge\OxPHP;
use OxPHP\Runtime\Internal\Fiber\RequestScopes;
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
    /**
     * @param array<array-key, mixed> $bootServer the $_SERVER entries the worker's
     *        boot wrote (dotenv, APP_ENV, ...); see Runtime::bootServerVars()
     */
    public function __construct(
        protected readonly OxPHP $bridge,
        protected readonly ResetterChain $resetters,
        private readonly array $bootServer = [],
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

        // Single boot — the worker loops internally. Each iteration gets a fresh
        // request via the SAPI soft reset; we catch Throwable so a broken handler
        // cannot poison the worker. The bridge picks Worker::serve() or the
        // oxphp_worker() fallback.
        //
        // OxPHP runs each iteration in a fiber of its own. The request scope is
        // opened first and closed last, after the resetters, so they still see
        // their own request as in flight; `finally` closes it when the handler
        // returns or throws. A request that ends in a fatal error, a time limit,
        // exit(), a cancel or a client gone mid-stream skips every `finally`, but
        // OxPHP still runs its shutdown functions: the same ticket is closed from
        // one, registered first so the app's own can't skip it, and writing no
        // output. Closing twice is a no-op; closing there marks the bailout.
        $this->bridge->serve(function (): void {
            $ticket = RequestScopes::enterRequest();

            try {
                $this->bridge->registerRequestShutdown(
                    static fn () => RequestScopes::leaveRequest($ticket, atShutdown: true),
                );

                // The soft reset gave this request a $_SERVER of its own, which
                // the boot never wrote into. Put the boot's entries back, never
                // overriding what the server set for the request.
                $_SERVER += $this->bootServer;
                $this->handleRequest($this->bridge->currentRequest());
            } catch (\Throwable $e) {
                $this->emitFallback500($e);
            } finally {
                try {
                    $this->resetters->reset();
                } finally {
                    RequestScopes::leaveRequest($ticket);
                }
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
