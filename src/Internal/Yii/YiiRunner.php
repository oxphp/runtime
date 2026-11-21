<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Yii;

use OxPHP\Http\RequestInterface as OxRequest;
use OxPHP\Runtime\Internal\Bridge\OxPHP;
use OxPHP\Runtime\Internal\Emitter\ResponseEmitter;
use OxPHP\Runtime\Internal\Reset\ResetterChain;
use OxPHP\Runtime\Internal\Runner\AbstractHttpRunner;
use OxPHP\Runtime\Internal\Runner\Psr7Support;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Yiisoft\ErrorHandler\ErrorHandler;
use Yiisoft\ErrorHandler\Middleware\ErrorCatcher;
use Yiisoft\PsrEmitter\EmitterInterface;
use Yiisoft\Yii\Http\Application;
use Yiisoft\Yii\Http\Handler\ThrowableHandler;
use Yiisoft\Yii\Runner\BootstrapRunner;

/**
 * @internal Serves every request of a Yii application from a container of its own.
 *
 * OxPHP runs overlapping requests in fibers on one worker thread, so a container
 * shared between requests would let one request's `CurrentRoute` (and any other
 * stateful service) leak into another's mid-flight. Each request therefore gets a
 * new container from the blueprint, which already holds the shared services; the
 * container is dropped when the request ends, never reused, and never reset.
 *
 * Per request it does what `HttpApplicationRunner::runInternal()` does: bootstrap,
 * `Application::handle()` and the emit preparation, `ErrorCatcher` with a
 * `ThrowableHandler` when either throws, then `afterEmit()`. A throw from building the
 * container, bootstrap, `ErrorCatcher` or emitting goes up to `AbstractHttpRunner`,
 * which logs it and sends its fallback 500; a throw from `afterEmit()` is only
 * logged, since the response is already out.
 */
final class YiiRunner extends AbstractHttpRunner
{
    private readonly ResponseEmitter $responseEmitter;
    private ?string $serverSoftware = null;

    /**
     * @param list<callable> $bootstrapList the bootstrap group's callbacks, run against each request container
     * @param EmitterInterface|null $emitter sends the response instead of the OxPHP emitter
     * @param bool $debug whether each request container's `ErrorHandler` renders verbosely
     */
    public function __construct(
        OxPHP $bridge,
        private readonly ContainerBlueprint $blueprint,
        private readonly array $bootstrapList,
        private readonly ?EmitterInterface $emitter = null,
        private readonly bool $debug = false,
    ) {
        // A request container is never reused, so there is no state to reset; yiisoft/app
        // reads its environment from $_ENV/getenv() at boot, so no $_SERVER carry-over either.
        parent::__construct($bridge, new ResetterChain([]));
        $this->responseEmitter = new ResponseEmitter($bridge);
    }

    protected function handleRequest(OxRequest $req): void
    {
        try {
            $container = $this->blueprint->createRequestContainer();

            // As `HttpApplicationRunner` does to its one container's handler: `ErrorCatcher`
            // renders through this container's own `ErrorHandler`, not the boot container's.
            if ($this->debug) {
                $container->get(ErrorHandler::class)->debug();
            }

            if ($this->bootstrapList !== []) {
                new BootstrapRunner($container, $this->bootstrapList)->run();
            }

            /** @var Application $app */
            $app = $container->get(Application::class);

            /** @var StreamFactoryInterface $streams */
            $streams = $container->get(StreamFactoryInterface::class);
            $request = ServerRequestBuilder::build(
                $req,
                $container->get(ServerRequestFactoryInterface::class),
                $streams,
                $container->get(UploadedFileFactoryInterface::class),
                $this->serverSoftware ??= $this->bridge->serverSoftware(),
            )->withAttribute('applicationStartTime', $req->startTime(true));

            try {
                try {
                    $response = $app->handle($request);
                    // Preparing reads the body, which renders a Yii view: a throw there still gets ErrorCatcher's response.
                    $prepared = ResponsePreparer::prepare($request, $response, $streams);
                } catch (\Throwable $e) {
                    /** @var ResponseInterface $response */
                    $response = $container->get(ErrorCatcher::class)->process($request, new ThrowableHandler($e));
                    $prepared = ResponsePreparer::prepare($request, $response, $streams);
                }

                // Once emitting starts, headers may be out: a throw goes up as is, with no second ErrorCatcher pass.
                $this->emit($prepared);
            } finally {
                try {
                    $app->afterEmit($response ?? null);
                } catch (\Throwable $afterEmitError) {
                    // The response is already out; a fallback 500 would only be appended to it.
                    \error_log('OxPHP runtime: Application::afterEmit() threw: ' . $afterEmitError);
                }
            }
        } finally {
            // Request containers hold reference cycles; collect them before the next request.
            // Every local of the request goes first: a response or a throwable's trace can
            // reach the container, and anything still held keeps it alive through the collection.
            // A throwable propagating from here is held by the engine, not by these variables.
            unset($container, $app, $streams, $request, $response, $prepared, $e, $afterEmitError);
            \gc_collect_cycles();
        }
    }

    private function emit(ResponseInterface $response): void
    {
        if ($this->emitter !== null) {
            $this->emitter->emit($response);

            return;
        }

        // PHP queues headers of its own, such as the session cache limiter's Expires, Pragma
        // and Cache-Control; the emitter adds to them, so they would be merged into the
        // response. Yii's SapiEmitter drops them first, and so does this.
        if (!\headers_sent()) {
            \header_remove();
        }

        $this->responseEmitter->emit(
            $response->getStatusCode(),
            $response->getHeaders(),
            Psr7Support::bodyChunks($response->getBody()),
        );
    }
}
