<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub\Yii\Guard;

use Yiisoft\Router\CurrentRoute;

final class NeedsCurrentRoute
{
    public function __construct(public readonly CurrentRoute $route) {}
}
