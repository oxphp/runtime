<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Yii;

use Psr\Container\ContainerInterface;
use Yiisoft\Config\ConfigInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;
use Yiisoft\Di\Helpers\DefinitionParser;

/**
 * @internal The DI config of a Yii application, read once at boot, from which the
 * shared container and a fresh container for every request are built.
 *
 * It reads the groups `ApplicationRunner::createDefaultContainer()` reads, plus
 * `ConfigInterface => $config`, and flattens them the way `Container` consumes
 * them: each provider is built once, its definitions are merged into the
 * definitions in provider order (later ids win), and its extensions are kept in an
 * `ExtensionsProvider` of their own, so they still apply in the same order.
 *
 * Per-request services are the ids with a `reset` callback in any of those
 * definitions: `Container` keeps a callback even when a later definition of the
 * id has none.
 */
final class ContainerBlueprint
{
    /**
     * @param array<array-key, mixed> $definitions
     * @param list<ExtensionsProvider> $providers
     * @param array<array-key, mixed> $delegates
     * @param array<array-key, mixed> $tags
     * @param list<string> $perRequestIds
     * @param array<array-key, mixed> $requestDefinitions
     * @param list<ExtensionsProvider> $requestProviders
     */
    private function __construct(
        private readonly array $definitions,
        private readonly array $providers,
        private readonly array $delegates,
        private readonly array $tags,
        private readonly array $perRequestIds,
        private readonly array $requestDefinitions,
        private readonly array $requestProviders,
    ) {}

    public static function fromConfig(
        ConfigInterface $config,
        string $diGroup,
        string $diProvidersGroup,
        string $diDelegatesGroup,
        string $diTagsGroup,
    ): self {
        $definitions = self::group($config, $diGroup);
        $definitions[ConfigInterface::class] = $config;
        $perRequest = self::resetIds($definitions);

        $providers = [];
        foreach (self::group($config, $diProvidersGroup) as $provider) {
            $provider = \is_object($provider) ? $provider : new $provider();
            // The order `Container::addProviders()` calls them in.
            $providers[] = new ExtensionsProvider($provider->getExtensions());
            $providerDefinitions = $provider->getDefinitions();
            $perRequest += self::resetIds($providerDefinitions);
            foreach ($providerDefinitions as $id => $definition) {
                $definitions[$id] = $definition;
            }
        }

        return new self(
            $definitions,
            $providers,
            self::group($config, $diDelegatesGroup),
            self::group($config, $diTagsGroup),
            \array_keys($perRequest),
            $definitions,
            $providers,
        );
    }

    /**
     * @return list<string> ids whose definition has a "reset" meta key
     */
    public function perRequestIds(): array
    {
        return $this->perRequestIds;
    }

    /**
     * The container the shared services are built in. Each per-request service is
     * a stand-in that throws, in the tags of its definition, so a shared service
     * that depends on one, directly or through a tag, fails to build.
     */
    public function createSharedContainer(bool $validate): Container
    {
        $definitions = $this->definitions;
        foreach ($this->perRequestIds as $id) {
            $definitions[$id] = self::inTagsOf(
                $this->definitions[$id],
                static fn (): never => throw new PerRequestServiceRequested($id),
            );
        }

        return $this->container($validate, $definitions, $this->providers);
    }

    /**
     * A blueprint whose request containers return these instances, in the tags of
     * their definitions, and no longer apply provider extensions to them: those
     * ran when the instances were built.
     *
     * @param array<string, mixed> $instances id => built shared instance
     */
    public function withSharedInstances(array $instances): self
    {
        $definitions = $this->definitions;
        foreach ($instances as $id => $instance) {
            // A closure that returns the instance, not the instance itself: a container
            // reads a string as a class name or an alias, an array as an array
            // definition and calls a callable, and it rejects a `ValueDefinition`.
            $definitions[$id] = self::inTagsOf($this->definitions[$id] ?? null, static fn (): mixed => $instance);
        }

        $providers = [];
        foreach ($this->providers as $provider) {
            $providers[] = new ExtensionsProvider(\array_diff_key($provider->getExtensions(), $instances));
        }

        return new self(
            $this->definitions,
            $this->providers,
            $this->delegates,
            $this->tags,
            $this->perRequestIds,
            $definitions,
            $providers,
        );
    }

    public function createRequestContainer(bool $validate = false): ContainerInterface
    {
        // As `ApplicationRunner::getContainer()` does: a definition may replace it.
        return $this->container($validate, $this->requestDefinitions, $this->requestProviders)
            ->get(ContainerInterface::class);
    }

    /**
     * @param array<array-key, mixed> $definitions
     * @param list<ExtensionsProvider> $providers
     */
    private function container(bool $validate, array $definitions, array $providers): Container
    {
        return new Container(
            ContainerConfig::create()
                ->withValidate($validate)
                ->withDefinitions($definitions)
                ->withProviders($providers)
                ->withDelegates($this->delegates)
                ->withTags($this->tags),
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function group(ConfigInterface $config, string $group): array
    {
        // A missing group is not configured, as in `ApplicationRunner::getConfiguration()`.
        return $config->has($group) ? $config->get($group) : [];
    }

    /**
     * @param array<array-key, mixed> $definitions
     *
     * @return array<array-key, true>
     */
    private static function resetIds(array $definitions): array
    {
        $ids = [];
        foreach ($definitions as $id => $definition) {
            if (isset(DefinitionParser::parse($definition)[1]['reset'])) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /**
     * $definition, carrying the tags of $original if it has any.
     */
    private static function inTagsOf(mixed $original, \Closure $definition): mixed
    {
        $meta = DefinitionParser::parse($original)[1];

        return isset($meta['tags']) ? ['definition' => $definition, 'tags' => $meta['tags']] : $definition;
    }
}
