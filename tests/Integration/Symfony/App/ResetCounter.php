<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Integration\Symfony\App;

use Symfony\Contracts\Service\ResetInterface;

/**
 * A `kernel.reset` service that counts how often `services_resetter` reset it.
 */
final class ResetCounter implements ResetInterface
{
    public int $resets = 0;

    public function reset(): void
    {
        ++$this->resets;
    }
}
