<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub\Yii\Guard;

/**
 * A named class to make lazy ghosts and proxies of, which can't be made from an
 * anonymous class.
 */
final class LazyService
{
    public ?object $value = null;

    // A second property: a ghost whose every property is set counts as initialized.
    public ?object $other = null;

    /**
     * An initializer callable that isn't a closure: `[$factory, 'initialize']`.
     */
    public static function factory(object $held): object
    {
        return new class ($held) {
            public function __construct(public readonly object $held) {}

            public function initialize(LazyService $service): void
            {
                $service->value = $this->held;
            }
        };
    }
}
