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
 * The meta of every definition, overridden ones included, is read as `Container`
 * reads it:
 * - `tags` are moved into one tag map, starting from the tags group, in the order
 *   and with the de-duplication of `Container::setDefinitionTags()`, so an id
 *   defined twice is in the tags of both definitions. Every container gets that
 *   map, so a replaced definition (a stand-in, a shared instance) keeps the tags;
 * - an id with a `reset` callback is per-request: `Container` keeps a callback even
 *   when a later definition of the id has none.
 */
final class ContainerBlueprint
{
    /**
     * @param array<array-key, mixed> $definitions without `tags` meta
     * @param list<ExtensionsProvider> $providers
     * @param array<array-key, mixed> $delegates
     * @param array<array-key, mixed> $tags tag => service ids
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
        $definitions = [];
        $tags = self::group($config, $diTagsGroup);
        $perRequest = [];

        $di = self::group($config, $diGroup);
        $di[ConfigInterface::class] = $config;
        self::add($di, $definitions, $tags, $perRequest);

        $providers = [];
        foreach (self::group($config, $diProvidersGroup) as $provider) {
            $provider = \is_object($provider) ? $provider : new $provider();
            // The order `Container::addProviders()` calls them in.
            $providers[] = new ExtensionsProvider($provider->getExtensions());
            self::add($provider->getDefinitions(), $definitions, $tags, $perRequest);
        }

        return new self(
            $definitions,
            $providers,
            self::group($config, $diDelegatesGroup),
            $tags,
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
     * a stand-in that throws, so a shared service that depends on one, directly or
     * through a tag, fails to build.
     */
    public function createSharedContainer(bool $validate): Container
    {
        $definitions = $this->definitions;
        foreach ($this->perRequestIds as $id) {
            $definitions[$id] = static fn (): never => throw new PerRequestServiceRequested($id);
        }

        return $this->container($validate, $definitions, $this->providers);
    }

    /**
     * A blueprint whose request containers return these instances and no longer
     * apply provider extensions to them: those ran when the instances were built.
     *
     * @param array<string, mixed> $instances id => built shared instance
     */
    public function withSharedInstances(array $instances): self
    {
        $definitions = $this->definitions;
        foreach ($instances as $id => $instance) {
            // A closure: a container rejects a `ValueDefinition` and reads a bare value as a definition.
            $definitions[$id] = static fn (): mixed => $instance;
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
     * Adds $source to $definitions as `Container::addDefinitions()` would: a later
     * id wins, its `tags` go into $tags, and a `reset` callback marks it per-request.
     *
     * @param array<array-key, mixed> $source
     * @param array<array-key, mixed> $definitions
     * @param array<array-key, mixed> $tags
     * @param array<array-key, true> $perRequest
     */
    private static function add(array $source, array &$definitions, array &$tags, array &$perRequest): void
    {
        foreach ($source as $id => $definition) {
            $meta = DefinitionParser::parse($definition)[1];
            if (isset($meta['reset'])) {
                $perRequest[$id] = true;
            }
            // Anything but a list is left for `Container` to reject.
            if (isset($meta['tags']) && \is_array($meta['tags'])) {
                foreach ($meta['tags'] as $tag) {
                    if (!\in_array($id, $tags[$tag] ?? [], true)) {
                        $tags[$tag][] = $id;
                    }
                }
                // Meta is a top-level key in both the array and the "definition" form.
                unset($definition['tags']);
            }
            $definitions[$id] = $definition;
        }
    }
}
