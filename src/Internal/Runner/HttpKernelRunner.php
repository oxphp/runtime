<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Runner;

use OxPHP\Http\RequestInterface as OxRequest;
use OxPHP\Runtime\Internal\Bridge\OxPHP;
use OxPHP\Runtime\Internal\Emitter\ResponseEmitter;
use OxPHP\Runtime\Internal\Reset\ResetterChain;
use OxPHP\Runtime\Internal\Reset\SymfonyContainerResetter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * @internal Runs a Symfony HttpKernel against every incoming OxPHP request.
 *
 * When the kernel is a full Symfony\Component\HttpKernel\Kernel, the
 * container's services_resetter (kernel.reset tag) runs between requests
 * automatically, prepended to the user resetter chain.
 */
final class HttpKernelRunner extends AbstractHttpRunner
{
    private readonly ResponseEmitter $emitter;
    private readonly HttpFoundationRequestBuilder $builder;

    public function __construct(
        private readonly HttpKernelInterface $kernel,
        OxPHP $bridge,
        array $userResetters,
    ) {
        $builtin = $kernel instanceof Kernel
            ? [new SymfonyContainerResetter($kernel)]
            : [];
        parent::__construct($bridge, new ResetterChain([...$builtin, ...$userResetters]));
        $this->emitter = new ResponseEmitter($bridge);
        $this->builder = new HttpFoundationRequestBuilder();
    }

    protected function handleRequest(OxRequest $ox): void
    {
        $request = $this->builder->build($ox);
        $response = $this->kernel->handle($request);

        $this->emit($response);

        if ($this->kernel instanceof TerminableInterface) {
            $this->kernel->terminate($request, $response);
        }
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
