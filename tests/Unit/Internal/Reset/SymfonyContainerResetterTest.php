<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Reset;

use OxPHP\Runtime\Internal\Reset\SymfonyContainerResetter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Contracts\Service\ResetInterface;

final class SymfonyContainerResetterTest extends TestCase
{
    public function test_calls_reset_when_container_implements_reset_interface(): void
    {
        $container = new class () implements ContainerInterface, ResetInterface {
            public int $calls = 0;
            public function reset(): void
            {
                $this->calls++;
            }
            public function set(string $id, ?object $service): void {}
            public function get(string $id, int $invalidBehavior = 1): ?object
            {
                return null;
            }
            public function has(string $id): bool
            {
                return false;
            }
            public function initialized(string $id): bool
            {
                return false;
            }
            public function getParameter(string $name): array|bool|string|int|float|\UnitEnum|null
            {
                return null;
            }
            public function hasParameter(string $name): bool
            {
                return false;
            }
            public function setParameter(string $name, array|bool|string|int|float|\UnitEnum|null $value): void {}
        };

        $kernel = $this->createKernelReturning($container);

        new SymfonyContainerResetter($kernel)->reset();
        self::assertSame(1, $container->calls);
    }

    public function test_is_noop_when_container_does_not_implement_reset_interface(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $kernel = $this->createKernelReturning($container);

        new SymfonyContainerResetter($kernel)->reset();
        self::assertTrue(true); // no error
    }

    private function createKernelReturning(ContainerInterface $container): Kernel
    {
        $kernel = $this->createMock(Kernel::class);
        $kernel->method('getContainer')->willReturn($container);
        return $kernel;
    }
}
