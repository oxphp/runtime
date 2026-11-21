<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub\ServicesResetter;

use Symfony\Component\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface as HttpKernelServicesResetterInterface;

/**
 * A `services_resetter` class that implements both `ServicesResetterInterface`s, like
 * HttpKernel's own `ServicesResetter` on 8.1 (which FrameworkBundle 8.0 registers).
 *
 * Load it only with symfony/dependency-injection 8.1 and symfony/http-kernel 7.3 or
 * later, and only in a separate process: on http-kernel 8.1 loading it loads
 * HttpKernel's deprecated interface.
 */
final class BothInterfacesResetter implements ServicesResetterInterface, HttpKernelServicesResetterInterface
{
    public function reset(): void {}
}
