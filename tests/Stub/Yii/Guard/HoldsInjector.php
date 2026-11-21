<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub\Yii\Guard;

use Yiisoft\Injector\Injector;

final class HoldsInjector
{
    public function __construct(public readonly Injector $injector) {}
}
