<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Symfony;

use OxPHP\Runtime\Internal\Symfony\FiberIsolationPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Isolates Symfony's per-request services between requests that overlap on one OxPHP worker.
 *
 * OxPHP runs several requests concurrently on one worker, each in its own fiber.
 * Without this bundle they share the request stack, router context, security token,
 * translator locale and the like. With it, those services keep their state per
 * request, and the container parameter `oxphp_runtime.fiber_isolation` tells the
 * runtime so. Outside OxPHP worker mode every service behaves exactly like the
 * stock one.
 *
 * Register it in `config/bundles.php`:
 *
 *     OxPHP\Runtime\Symfony\OxPHPBundle::class => ['all' => true],
 *
 * When a service can't be isolated safely (a custom class, an unsupported Symfony
 * version), the bundle changes nothing and records why in the container parameter
 * `oxphp_runtime.fiber_isolation_reason`.
 */
final class OxPHPBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new FiberIsolationPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION);
    }
}
