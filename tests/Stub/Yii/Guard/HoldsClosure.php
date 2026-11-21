<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub\Yii\Guard;

use Psr\Container\ContainerInterface;

/**
 * Keeps the container only inside a closure, not in a property of its own.
 */
final class HoldsClosure
{
    public readonly \Closure $resolve;

    public function __construct(ContainerInterface $c)
    {
        $this->resolve = static fn (): ContainerInterface => $c;
    }
}
