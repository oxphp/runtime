<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub\Yii\Guard;

final class NeedsTaggedProbes
{
    /**
     * @param list<object> $probes
     */
    public function __construct(public readonly array $probes) {}
}
