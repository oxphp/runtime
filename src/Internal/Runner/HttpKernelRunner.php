<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Runner;

use OxPHP\Http\RequestInterface as OxRequest;
use OxPHP\Runtime\Internal\Bridge\OxPHP;
use OxPHP\Runtime\Internal\Reset\ResetterChain;
use OxPHP\Runtime\Internal\Reset\SymfonyContainerResetter;
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
    private readonly HttpFoundationResponseEmitter $emitter;
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
        $this->emitter = new HttpFoundationResponseEmitter($bridge);
        $this->builder = new HttpFoundationRequestBuilder($bridge->serverSoftware());
    }

    protected function handleRequest(OxRequest $ox): void
    {
        $request = $this->builder->build($ox);
        $response = $this->kernel->handle($request);

        $this->emitter->emit($response);

        if ($this->kernel instanceof TerminableInterface) {
            $this->kernel->terminate($request, $response);
        }
    }
}
