<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Symfony;

use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface;

/**
 * @internal The `services_resetter` guard for symfony/http-kernel 7.3 to 8.0.
 *
 * HttpKernel's `ServicesResetterInterface` exists from 7.3 and is deprecated in 8.1,
 * where loading it triggers a deprecation. Autoload this class only when
 * symfony/dependency-injection's `ServicesResetterInterface` does not exist and
 * HttpKernel's does; the compiler pass that installs it guarantees that.
 */
final class LegacyGuardedServicesResetter extends AbstractGuardedServicesResetter implements ServicesResetterInterface {}
