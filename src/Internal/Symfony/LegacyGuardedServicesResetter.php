<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Symfony;

use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface;

/**
 * @internal The guard for a `services_resetter` that implements symfony/http-kernel's `ServicesResetterInterface` (7.3 on).
 *
 * HttpKernel's interface exists from 7.3 and is deprecated in 8.1, where loading it
 * triggers a deprecation. Autoload this class only when a loaded class implements
 * it; the compiler pass installs it for every `services_resetter` class that
 * implements HttpKernel's interface, also when it implements DependencyInjection's
 * too. On 8.1 HttpKernel's extends DependencyInjection's, so there this guard keeps
 * both types. That includes FrameworkBundle 8.0 with DependencyInjection 8.1, which
 * still registers HttpKernel's `ServicesResetter`.
 */
final class LegacyGuardedServicesResetter extends AbstractGuardedServicesResetter implements ServicesResetterInterface {}
