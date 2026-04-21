<?php
declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Reset;

use OxPHP\Runtime\Internal\Reset\SymfonyContainerResetter;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Service\ResetInterface;

final class SymfonyContainerResetterTest extends TestCase
{
    public function test_calls_reset_when_container_implements_reset_interface(): void
    {
        $container = new class implements ResetInterface {
            public int $calls = 0;
            public function reset(): void { $this->calls++; }
        };

        (new SymfonyContainerResetter($container))->reset();
        self::assertSame(1, $container->calls);
    }

    public function test_is_noop_when_container_does_not_implement_reset_interface(): void
    {
        $plain = new \stdClass();
        (new SymfonyContainerResetter($plain))->reset();
        self::assertTrue(true); // no error
    }
}
