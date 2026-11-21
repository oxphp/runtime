<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Fiber;

use OxPHP\Runtime\Internal\Fiber\RequestScope;
use PHPUnit\Framework\TestCase;

final class RequestScopeTest extends TestCase
{
    public function test_get_stores_the_initial_value_on_first_access_only(): void
    {
        $scope = new RequestScope();
        $owner = new \stdClass();
        $calls = 0;
        $initial = static function () use (&$calls): string {
            ++$calls;

            return 'first';
        };

        self::assertSame('first', $scope->get($owner, $initial));
        self::assertSame('first', $scope->get($owner, $initial));
        self::assertSame(1, $calls);
    }

    public function test_a_stored_null_counts_as_stored(): void
    {
        $scope = new RequestScope();
        $owner = new \stdClass();
        $calls = 0;

        $scope->set($owner, null);

        $value = $scope->get($owner, static function () use (&$calls): string {
            ++$calls;

            return 'initial';
        });

        self::assertNull($value);
        self::assertSame(0, $calls);
        self::assertNull($scope->get($owner, static fn (): string => 'still not called'));
    }

    public function test_an_initial_that_returns_null_runs_once(): void
    {
        $scope = new RequestScope();
        $owner = new \stdClass();
        $calls = 0;
        $initial = static function () use (&$calls): null {
            ++$calls;

            return null;
        };

        self::assertNull($scope->get($owner, $initial));
        self::assertNull($scope->get($owner, $initial));
        self::assertSame(1, $calls);
    }

    public function test_values_are_kept_per_owner(): void
    {
        $scope = new RequestScope();
        $one = new \stdClass();
        $two = new \stdClass();

        $scope->set($one, 'one');

        self::assertSame('one', $scope->get($one, static fn (): string => 'unset'));
        self::assertSame('two', $scope->get($two, static fn (): string => 'two'));
    }
}
