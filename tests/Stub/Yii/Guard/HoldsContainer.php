<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub\Yii\Guard;

use Psr\Container\ContainerInterface;

final class HoldsContainer
{
    public function __construct(public readonly ContainerInterface $container) {}
}
