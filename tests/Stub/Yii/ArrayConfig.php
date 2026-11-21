<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub\Yii;

use Yiisoft\Config\ConfigInterface;

/**
 * Config groups given as arrays, in place of a `yiisoft/config` merge plan.
 */
final class ArrayConfig implements ConfigInterface
{
    /**
     * @param array<string, array<array-key, mixed>> $groups
     */
    public function __construct(private readonly array $groups) {}

    public function get(string $group): array
    {
        if (!\array_key_exists($group, $this->groups)) {
            throw new \LogicException(\sprintf('Unknown config group "%s".', $group));
        }

        return $this->groups[$group];
    }

    public function has(string $group): bool
    {
        return \array_key_exists($group, $this->groups);
    }
}
