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
use Symfony\Component\HttpKernel\Kernel;

/**
 * The `ServicesResetter` classes of the installed Symfony: symfony/dependency-injection
 * 8.1 moved them there from symfony/http-kernel.
 *
 * DependencyInjection is checked first and HttpKernel's classes are only named, never
 * autoloaded, when it has them: from http-kernel 8.1 they trigger a deprecation when loaded.
 */
final class InstalledServicesResetter
{
    public static function inDependencyInjection(): bool
    {
        return \interface_exists(ServicesResetterInterface::class);
    }

    /**
     * Whether HttpKernel's `ServicesResetterInterface` exists and is not deprecated:
     * http-kernel 7.3 to 8.0. Reads the version, so the interface isn't autoloaded.
     */
    public static function inHttpKernel(): bool
    {
        return Kernel::VERSION_ID >= 70300 && Kernel::VERSION_ID < 80100;
    }

    /**
     * The guard `FiberIsolationPass` installs around `stock()`; skips the test on http-kernel < 7.3.
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
     * The installed `ServicesResetter`: DependencyInjection's when it has one, else HttpKernel's.
     *
     * @return class-string<LegacyServicesResetter|ServicesResetter>
     */
    public static function stock(): string
    {
        return self::inDependencyInjection() ? ServicesResetter::class : LegacyServicesResetter::class;
    }
}
