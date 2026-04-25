<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Runner;

use Illuminate\Contracts\Http\Kernel as LaravelKernel;
use Illuminate\Http\Request as IlluminateRequest;
use OxPHP\Http\RequestInterface as OxRequest;
use OxPHP\Runtime\Internal\Bridge\OxPHP;
use OxPHP\Runtime\Internal\Emitter\ResponseEmitter;
use OxPHP\Runtime\Internal\Reset\ResetterChain;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @internal Runs a Laravel HTTP Kernel against every incoming OxPHP request.
 *
 * Reset strategy: Laravel's per-request teardown happens via $kernel->terminate()
 * inside each handleRequest(). Container-level reset (rebinding singletons,
 * flushing DB connections, etc.) is the user's responsibility via resetters
 * — this mirrors Octane's flush-bindings pattern.
 */
final class LaravelRunner extends AbstractHttpRunner
{
    private readonly ResponseEmitter $emitter;
    private readonly HttpFoundationRequestBuilder $builder;

    public function __construct(
        private readonly LaravelKernel $kernel,
        OxPHP $bridge,
        array $userResetters,
    ) {
        parent::__construct($bridge, new ResetterChain($userResetters));
        $this->emitter = new ResponseEmitter($bridge);
        $this->builder = new HttpFoundationRequestBuilder();
    }

    protected function handleRequest(OxRequest $ox): void
    {
        $base = $this->builder->build($ox);
        $request = IlluminateRequest::createFromBase($base);
        $response = $this->kernel->handle($request);

        $this->emit($response);
        $this->kernel->terminate($request, $response);
    }

    private function emit(Response $response): void
    {
        $headers = [];
        foreach ($response->headers->all() as $name => $values) {
            $headers[$name] = $values;
        }

        if ($response instanceof StreamedResponse) {
            $this->emitter->emit(
                $response->getStatusCode(),
                $headers,
                $this->driveStreamedCallback($response),
            );
            return;
        }

        $this->emitter->emit(
            $response->getStatusCode(),
            $headers,
            (string) $response->getContent(),
        );
    }

    /** @return \Generator<string> */
    private function driveStreamedCallback(StreamedResponse $response): \Generator
    {
        $callback = (function (): ?\Closure {
            return $this->callback;
        })->call($response);
        if ($callback === null) {
            return;
        }

        $chunks = [];
        \ob_start(static function (string $buffer) use (&$chunks): string {
            if ($buffer !== '') {
                $chunks[] = $buffer;
            }
            return '';
        }, 1);

        try {
            $callback();
        } finally {
            \ob_end_flush();
        }

        foreach ($chunks as $chunk) {
            yield $chunk;
        }
    }
}
