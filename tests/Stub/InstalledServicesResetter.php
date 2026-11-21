<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub;

use OxPHP\Runtime\Internal\Symfony\GuardedServicesResetter;
use OxPHP\Runtime\Internal\Symfony\LegacyGuardedServicesResetter;
use PHPUnit\Framework\Assert;
use Symfony\Component\DependencyInjection\ServicesResetter;
use Symfony\Component\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetter as LegacyServicesResetter;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface as LegacyServicesResetterInterface;

/**
 * The `services_resetter` classes of the installed Symfony, picked the way `FiberIsolationPass`
 * picks its guard: symfony/dependency-injection 8.1 moved them there from symfony/http-kernel.
 *
 * DependencyInjection is checked first and HttpKernel's classes are only named, never
 * autoloaded, when it has them: from 8.1 they trigger a deprecation when loaded.
 */
final class InstalledServicesResetter
{
    public static function inDependencyInjection(): bool
    {
        return \interface_exists(ServicesResetterInterface::class);
    }

    /**
     * The guard `FiberIsolationPass` installs on this vendor; skips the test on http-kernel < 7.3.
     *
     * @return class-string<GuardedServicesResetter|LegacyGuardedServicesResetter>
     */
    public static function guard(): string
    {
        if (self::inDependencyInjection()) {
            return GuardedServicesResetter::class;
        }

        if (\interface_exists(LegacyServicesResetterInterface::class)) {
            return LegacyGuardedServicesResetter::class;
        }

        Assert::markTestSkipped('symfony/http-kernel 7.3 or later is required');
    }

    /**
     * The class Symfony registers as `services_resetter` on this vendor.
     *
     * @return class-string<ServicesResetter|LegacyServicesResetter>
     */
    public static function stock(): string
    {
        return self::inDependencyInjection() ? ServicesResetter::class : LegacyServicesResetter::class;
    }
}
