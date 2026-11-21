<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Symfony;

use Symfony\Component\DependencyInjection\ServicesResetterInterface;

/**
 * @internal The `services_resetter` guard for symfony/dependency-injection 8.1 or later.
 *
 * `ServicesResetterInterface` exists in symfony/dependency-injection only from 8.1.
 * Autoload this class only when it exists; the compiler pass that installs it
 * guarantees that.
 */
final class GuardedServicesResetter extends AbstractGuardedServicesResetter implements ServicesResetterInterface {}
