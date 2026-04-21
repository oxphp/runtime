<?php
declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Reset;

use OxPHP\Runtime\Resetter\ResetterInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * @internal Built-in resetter that triggers the Symfony container's
 * services_resetter (any service tagged `kernel.reset`).
 *
 * Safe to pass any object — if it doesn't implement ResetInterface, this
 * is a no-op. `HttpKernelRunner` prepends one of these to the chain when
 * the kernel is a full Symfony\Component\HttpKernel\Kernel.
 */
final class SymfonyContainerResetter implements ResetterInterface
{
    public function __construct(private readonly object $container) {}

    public function reset(): void
    {
        if ($this->container instanceof ResetInterface) {
            $this->container->reset();
        }
    }
}
