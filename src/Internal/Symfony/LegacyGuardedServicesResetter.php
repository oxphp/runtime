<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Symfony;

use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface;

/**
 * @internal The guard for a `services_resetter` that implements only symfony/http-kernel's `ServicesResetterInterface` (7.3 to 8.0).
 *
 * HttpKernel's interface exists from 7.3 and is deprecated in 8.1, where loading it
 * triggers a deprecation. Autoload this class only when a loaded class implements
 * it; the compiler pass installs it only for a `services_resetter` class that
 * implements HttpKernel's interface and not DependencyInjection's. That includes
 * DependencyInjection 8.1 with FrameworkBundle 8.0, which still registers
 * HttpKernel's `ServicesResetter`.
 */
final class LegacyGuardedServicesResetter extends AbstractGuardedServicesResetter implements ServicesResetterInterface {}
