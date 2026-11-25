<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Yii;

/**
 * @internal Builds the services an application shares between requests, once, at
 * boot, and rejects one that would carry per-request state into other requests:
 * a per-request service itself, a service that depends on one, and a service that
 * keeps a container or an `Injector` to resolve services later. It also rejects a
 * provider extension keyed on an alias of a listed service, which every request
 * would run again on the shared instance.
 */
final class SharedServices
{
    /**
     * @param list<string> $ids
     *
     * @return array<string, mixed>
     */
    public static function resolve(ContainerBlueprint $blueprint, array $ids, bool $validate): array
    {
        $perRequestIds = $blueprint->perRequestIds();
        foreach ($ids as $id) {
            if (\in_array($id, $perRequestIds, true)) {
                throw new \LogicException(\sprintf(
                    'Service "%s" cannot be shared: its definition has a "reset" callback, so it holds per-request state. Remove it from sharedServices.',
                    $id,
                ));
            }
        }

        foreach ($blueprint->extendedAliasesOf($ids) as $alias => $id) {
            throw new \LogicException(\sprintf(
                'A provider extends "%1$s", an alias of shared service "%2$s", so the extension would run again in every request, against the shared instance. Key the extension on "%2$s", or add "%1$s" to sharedServices.',
                $alias,
                $id,
            ));
        }

        $container = $blueprint->createSharedContainer($validate);
        $instances = [];
        foreach ($ids as $id) {
            try {
                $instance = $container->get($id);
            } catch (\Throwable $e) {
                for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
                    if ($cause instanceof PerRequestServiceRequested) {
                        throw new \LogicException(\sprintf(
                            'Shared service "%1$s" depends on per-request service "%2$s". Remove "%1$s" from sharedServices, or stop it depending on "%2$s".',
                            $id,
                            $cause->id,
                        ), 0, $e);
                    }
                }

                throw $e;
            }

            $held = ContainerReferenceFinder::find($instance);
            if ($held !== null) {
                throw new \LogicException(\sprintf(
                    'Shared service "%1$s" holds %2$s at %3$s. A shared service must not resolve services after boot; remove "%1$s" from sharedServices.',
                    $id,
                    $held['class'],
                    $held['path'] === '' ? 'the service itself' : 'property path ' . $held['path'],
                ));
            }

            $instances[$id] = $instance;
        }

        return $instances;
    }
}
