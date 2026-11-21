<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub\ServicesResetter;

use Symfony\Component\DependencyInjection\ServicesResetterInterface;

/**
 * A `services_resetter` class that implements DependencyInjection's `ServicesResetterInterface`.
 *
 * Load it only on symfony/dependency-injection 8.1 or later, which has the interface.
 */
final class DependencyInjectionResetter implements ServicesResetterInterface
{
    public function reset(): void {}
}
