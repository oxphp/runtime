<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Symfony;

use OxPHP\Runtime\Internal\Symfony\FiberIsolationPass;
use OxPHP\Runtime\Symfony\OxPHPBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class OxPHPBundleTest extends TestCase
{
    public function test_build_registers_the_fiber_isolation_pass(): void
    {
        $c = new ContainerBuilder();

        new OxPHPBundle()->build($c);

        $passes = \array_filter(
            $c->getCompilerPassConfig()->getBeforeOptimizationPasses(),
            static fn (object $pass): bool => $pass instanceof FiberIsolationPass,
        );
        self::assertCount(1, $passes);
    }
}
