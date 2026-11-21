<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub\ServicesResetter;

use Symfony\Contracts\Service\ResetInterface;

/**
 * A custom `services_resetter` class that implements neither `ServicesResetterInterface`.
 */
final class PlainResetter implements ResetInterface
{
    public function reset(): void {}
}
