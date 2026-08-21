<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Runner;

use Illuminate\Contracts\Http\Kernel as LaravelKernel;
use Illuminate\Http\Request as IlluminateRequest;
use OxPHP\Http\RequestInterface as OxRequest;
use OxPHP\Runtime\Internal\Bridge\OxPHP;
use OxPHP\Runtime\Internal\Reset\ResetterChain;

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
    private readonly HttpFoundationResponseEmitter $emitter;
    private readonly HttpFoundationRequestBuilder $builder;

    public function __construct(
        private readonly LaravelKernel $kernel,
        OxPHP $bridge,
        array $userResetters,
    ) {
        parent::__construct($bridge, new ResetterChain($userResetters));
        $this->emitter = new HttpFoundationResponseEmitter($bridge);
        $this->builder = new HttpFoundationRequestBuilder();
    }

    protected function handleRequest(OxRequest $ox): void
    {
        $base = $this->builder->build($ox);
        $request = IlluminateRequest::createFromBase($base);
        $response = $this->kernel->handle($request);

        $this->emitter->emit($response);
        $this->kernel->terminate($request, $response);
    }
}
