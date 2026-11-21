<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub\ServicesResetter;

use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface;

/**
 * A `services_resetter` class that implements only HttpKernel's `ServicesResetterInterface`.
 *
 * Load it only on symfony/http-kernel 7.3 to 8.0: the interface is missing before
 * 7.3, and from 8.1 it is deprecated and extends DependencyInjection's.
 */
final class HttpKernelResetter implements ServicesResetterInterface
{
    public function reset(): void {}
}
