<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Yii;

use OxPHP\Runtime\Internal\Yii\ContainerBlueprint;
use OxPHP\Runtime\Internal\Yii\SharedServices;
use OxPHP\Runtime\Tests\Stub\Yii\ArrayConfig;
use OxPHP\Runtime\Tests\Stub\Yii\PerRequestProbe;
use OxPHP\Runtime\Tests\Stub\Yii\SharedProbe;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Yiisoft\Config\ConfigInterface;
use Yiisoft\Definitions\Reference;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;
use Yiisoft\Di\ServiceProviderInterface;
use Yiisoft\Router\CurrentRoute;

final class ContainerBlueprintTest extends TestCase
{
    public function test_a_listed_service_is_the_same_instance_in_every_request_container(): void
    {
        $bp = $this->blueprint();
        $bp = $bp->withSharedInstances(SharedServices::resolve($bp, [SharedProbe::class], false));

        self::assertSame($bp->createRequestContainer()->get(SharedProbe::class), $bp->createRequestContainer()->get(SharedProbe::class));
    }

    public function test_an_unlisted_service_is_a_new_instance_in_each_request_container(): void
    {
        $bp = $this->blueprint();
        $bp = $bp->withSharedInstances(SharedServices::resolve($bp, [SharedProbe::class], false));

        self::assertNotSame($bp->createRequestContainer()->get(PerRequestProbe::class), $bp->createRequestContainer()->get(PerRequestProbe::class));
    }

    public function test_the_config_is_available_in_request_containers(): void
    {
        $config = $this->config();

        self::assertSame($config, $this->blueprint($config)->createRequestContainer()->get(ConfigInterface::class));
    }

    public function test_missing_groups_count_as_empty(): void
    {
        $config = new ArrayConfig([]);
        $bp = $this->blueprint($config);

        self::assertSame([], $bp->perRequestIds());
        self::assertSame($config, $bp->createRequestContainer()->get(ConfigInterface::class));
    }

    public function test_reset_ids_come_from_config_and_provider_definitions(): void
    {
        $provider = new class () implements ServiceProviderInterface {
            public function getDefinitions(): array
            {
                return ['p.state' => [
                    'class' => PerRequestProbe::class,
                    'reset' => function (): void {
                        $this->bootstrapped = false;
                    },
                ]];
            }

            public function getExtensions(): array
            {
                return [];
            }
        };

        // Listed by class name, as providers usually are.
        $bp = $this->blueprint($this->config(providers: [$provider::class]));

        self::assertSame([CurrentRoute::class, 'p.state'], $bp->perRequestIds());
    }

    public function test_provider_definitions_override_config_definitions(): void
    {
        $provider = new class () implements ServiceProviderInterface {
            public function getDefinitions(): array
            {
                return ['probe.source' => static fn (): string => 'provider'];
            }

            public function getExtensions(): array
            {
                return [];
            }
        };

        $bp = $this->blueprint($this->config(['probe.source' => static fn (): string => 'config'], providers: [$provider]));

        self::assertSame('provider', $bp->createRequestContainer()->get('probe.source'));
    }

    public function test_a_provider_extension_of_a_shared_service_runs_once(): void
    {
        $provider = self::countingProvider(SharedProbe::class);
        $bp = $this->blueprint($this->config(providers: [$provider]));
        $instances = SharedServices::resolve($bp, [SharedProbe::class], false);
        $bp = $bp->withSharedInstances($instances);

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame($instances[SharedProbe::class], $bp->createRequestContainer()->get(SharedProbe::class));
        }
        self::assertSame([SharedProbe::class => 1], $provider->calls);
    }

    public function test_a_provider_extension_of_an_unlisted_service_runs_in_each_request_container(): void
    {
        $provider = self::countingProvider(PerRequestProbe::class);
        $bp = $this->blueprint($this->config([PerRequestProbe::class => PerRequestProbe::class], providers: [$provider]));
        $bp = $bp->withSharedInstances(SharedServices::resolve($bp, [SharedProbe::class], false));

        for ($i = 0; $i < 3; ++$i) {
            $bp->createRequestContainer()->get(PerRequestProbe::class);
        }
        self::assertSame([PerRequestProbe::class => 3], $provider->calls);
    }

    public function test_a_shared_service_keeps_its_tags_in_request_containers(): void
    {
        $bp = $this->blueprint($this->config([SharedProbe::class => ['class' => SharedProbe::class, 'tags' => ['probes']]]));
        $instances = SharedServices::resolve($bp, [SharedProbe::class], false);
        $bp = $bp->withSharedInstances($instances);

        self::assertSame([$instances[SharedProbe::class]], $bp->createRequestContainer()->get('tag@probes'));
    }

    public function test_an_id_defined_again_by_a_provider_is_in_the_tags_of_both_definitions(): void
    {
        $provider = new class () implements ServiceProviderInterface {
            public function getDefinitions(): array
            {
                return [SharedProbe::class => ['class' => SharedProbe::class, 'tags' => ['b']]];
            }

            public function getExtensions(): array
            {
                return [];
            }
        };
        $bp = $this->blueprint($this->config(
            [SharedProbe::class => ['class' => SharedProbe::class, 'tags' => ['a']]],
            providers: [$provider],
        ));
        $instances = SharedServices::resolve($bp, [SharedProbe::class], false);
        $container = $bp->withSharedInstances($instances)->createRequestContainer();

        self::assertSame([$instances[SharedProbe::class]], $container->get('tag@a'));
        self::assertSame([$instances[SharedProbe::class]], $container->get('tag@b'));
    }

    public function test_tag_order_matches_a_container_built_from_the_same_config(): void
    {
        $provider = new class () implements ServiceProviderInterface {
            public function getDefinitions(): array
            {
                return [SharedProbe::class => ['class' => SharedProbe::class, 'tags' => ['x']]];
            }

            public function getExtensions(): array
            {
                return [];
            }
        };
        $config = $this->config(
            [
                // Also in the tags group, so it must not be added twice.
                CurrentRoute::class => [
                    'class' => CurrentRoute::class,
                    'tags' => ['x'],
                    'reset' => function (): void {
                        $this->route = null;
                    },
                ],
                PerRequestProbe::class => ['class' => PerRequestProbe::class, 'tags' => ['x']],
            ],
            providers: [$provider],
            tags: ['x' => [CurrentRoute::class]],
        );
        $reference = new Container(
            ContainerConfig::create()
                ->withDefinitions([...$config->get('di'), ConfigInterface::class => $config])
                ->withProviders($config->get('providers'))
                ->withTags($config->get('tags')),
        );
        $classes = static fn (array $services): array => \array_map(static fn (object $service): string => $service::class, $services);

        $expected = $classes($reference->get('tag@x'));
        self::assertSame([CurrentRoute::class, PerRequestProbe::class, SharedProbe::class], $expected);
        self::assertSame($expected, $classes($this->blueprint($config)->createRequestContainer()->get('tag@x')));
    }

    public function test_an_alias_to_a_listed_service_resolves_to_the_shared_instance(): void
    {
        $bp = $this->blueprint($this->config(['probe.alias' => Reference::to(SharedProbe::class)]));
        $instances = SharedServices::resolve($bp, [SharedProbe::class], false);
        $bp = $bp->withSharedInstances($instances);

        self::assertSame($instances[SharedProbe::class], $bp->createRequestContainer()->get('probe.alias'));
        self::assertSame($instances[SharedProbe::class], $bp->createRequestContainer()->get('probe.alias'));
    }

    public function test_listing_an_alias_shares_only_that_id(): void
    {
        $bp = $this->blueprint($this->config(['probe.alias' => Reference::to(SharedProbe::class)]));
        $instances = SharedServices::resolve($bp, ['probe.alias'], false);
        $bp = $bp->withSharedInstances($instances);
        $first = $bp->createRequestContainer();
        $second = $bp->createRequestContainer();

        self::assertSame($instances['probe.alias'], $first->get('probe.alias'));
        self::assertSame($instances['probe.alias'], $second->get('probe.alias'));
        self::assertNotSame($first->get(SharedProbe::class), $second->get(SharedProbe::class));
    }

    public function test_a_listed_value_reaches_request_containers_as_is(): void
    {
        // Each of these, given to a container as a definition, would be read as one:
        // a class name, an array definition, a callable.
        $bp = $this->blueprint($this->config([
            'shared.class-name' => static fn (): string => SharedProbe::class,
            'shared.array' => static fn (): array => ['class' => SharedProbe::class],
            'shared.closure' => static fn (): \Closure => static fn (): string => 'called',
        ]));
        $ids = ['shared.class-name', 'shared.array', 'shared.closure'];
        $instances = SharedServices::resolve($bp, $ids, false);
        $container = $bp->withSharedInstances($instances)->createRequestContainer();

        self::assertSame(SharedProbe::class, $container->get('shared.class-name'));
        self::assertSame(['class' => SharedProbe::class], $container->get('shared.array'));
        self::assertSame($instances['shared.closure'], $container->get('shared.closure'));
    }

    public function test_request_containers_with_shared_instances_pass_validation(): void
    {
        $bp = $this->blueprint($this->config([SharedProbe::class => ['class' => SharedProbe::class, 'tags' => ['probes']]]));
        $instances = SharedServices::resolve($bp, [SharedProbe::class], true);
        $container = $bp->withSharedInstances($instances)->createRequestContainer(true);

        self::assertSame($instances[SharedProbe::class], $container->get(SharedProbe::class));
    }

    public function test_delegates_and_tags_from_their_groups_reach_request_containers(): void
    {
        $delegate = new Container(ContainerConfig::create()->withDefinitions([
            'probe.delegated' => static fn (): string => 'delegated',
        ]));
        $bp = $this->blueprint($this->config(
            delegates: [static fn (ContainerInterface $container): ContainerInterface => $delegate],
            tags: ['probes' => [PerRequestProbe::class]],
        ));
        $container = $bp->createRequestContainer();

        self::assertSame('delegated', $container->get('probe.delegated'));
        self::assertSame([$container->get(PerRequestProbe::class)], $container->get('tag@probes'));
    }

    private function blueprint(?ArrayConfig $config = null): ContainerBlueprint
    {
        return ContainerBlueprint::fromConfig($config ?? $this->config(), 'di', 'providers', 'delegates', 'tags');
    }

    /**
     * @param array<string, mixed> $di
     * @param list<object|string> $providers
     * @param list<\Closure> $delegates
     * @param array<string, list<string>> $tags
     */
    private function config(array $di = [], array $providers = [], array $delegates = [], array $tags = []): ArrayConfig
    {
        return new ArrayConfig([
            'di' => [
                CurrentRoute::class => [
                    'class' => CurrentRoute::class,
                    'reset' => function (): void {
                        $this->route = null;
                        $this->uri = null;
                        $this->arguments = [];
                    },
                ],
                SharedProbe::class => SharedProbe::class,
                ...$di,
            ],
            'providers' => $providers,
            'delegates' => $delegates,
            'tags' => $tags,
        ]);
    }

    /**
     * A provider whose extensions count how often each id is extended.
     */
    private static function countingProvider(string ...$ids): object
    {
        return new class ($ids) implements ServiceProviderInterface {
            /** @var array<string, int> */
            public array $calls;

            /**
             * @param list<string> $ids
             */
            public function __construct(private readonly array $ids)
            {
                $this->calls = \array_fill_keys($ids, 0);
            }

            public function getDefinitions(): array
            {
                return [];
            }

            public function getExtensions(): array
            {
                $extensions = [];
                foreach ($this->ids as $id) {
                    $extensions[$id] = function (ContainerInterface $container, object $service) use ($id): object {
                        ++$this->calls[$id];

                        return $service;
                    };
                }

                return $extensions;
            }
        };
    }
}
