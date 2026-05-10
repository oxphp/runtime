<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Reset;

use OxPHP\Runtime\Resetter\ResetterInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Contracts\Service\ResetInterface;

/**
 * @internal Built-in resetter that triggers the Symfony container's
 * services_resetter (any service tagged `kernel.reset`).
 *
 * The container is resolved lazily via Kernel::getContainer() — calling it
 * eagerly in the runner constructor would throw, since the kernel is not
 * booted until the first Kernel::handle() call. By the time reset() runs
 * (after handleRequest in the worker loop), boot has happened.
 */
final class SymfonyContainerResetter implements ResetterInterface
{
    public function __construct(private readonly Kernel $kernel) {}

    public function reset(): void
    {
        $container = $this->kernel->getContainer();
        if ($container instanceof ResetInterface) {
            $container->reset();
        }
    }
}
