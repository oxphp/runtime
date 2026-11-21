<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Yii;

use Yiisoft\Di\ServiceProviderInterface;

/**
 * @internal A service provider that carries only extensions. `ContainerBlueprint`
 * moves each configured provider's definitions into the container definitions
 * once, at boot, and keeps that provider's extensions in one of these.
 */
final class ExtensionsProvider implements ServiceProviderInterface
{
    /**
     * @param array<array-key, mixed> $extensions service id => extension callable
     */
    public function __construct(private readonly array $extensions) {}

    public function getDefinitions(): array
    {
        return [];
    }

    public function getExtensions(): array
    {
        return $this->extensions;
    }
}
