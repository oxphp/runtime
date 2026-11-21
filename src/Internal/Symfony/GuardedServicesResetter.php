<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Symfony;

use Symfony\Component\DependencyInjection\ServicesResetterInterface;

/**
 * @internal The guard for a `services_resetter` that implements symfony/dependency-injection's `ServicesResetterInterface`.
 *
 * That interface exists only from symfony/dependency-injection 8.1. Autoload this
 * class only when it exists; the compiler pass installs it only for a
 * `services_resetter` class that implements it and not symfony/http-kernel's.
 */
final class GuardedServicesResetter extends AbstractGuardedServicesResetter implements ServicesResetterInterface {}
