<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Fiber;

use OxPHP\Runtime\Internal\Fiber\RequestScope;
use OxPHP\Runtime\Internal\Fiber\RequestScopes;
use OxPHP\Runtime\Tests\Stub\RequestFiber;
use PHPUnit\Framework\TestCase;

final class RequestScopesTest extends TestCase
{
    protected function setUp(): void
    {
        RequestScopes::resetForTests();
    }

    protected function tearDown(): void
    {
        RequestScopes::resetForTests();
    }

    public function test_outside_worker_mode_every_context_shares_one_scope(): void
    {
        $fromMain = RequestScopes::current();

        $fiber = new \Fiber(static fn (): RequestScope => RequestScopes::current());
        $fiber->start();

        self::assertSame($fromMain, $fiber->getReturn());
    }

    public function test_each_request_fiber_gets_its_own_scope(): void
    {
        $owner = new \stdClass();

        $body = static function (string $value) use ($owner): \Closure {
            return static function () use ($owner, $value): array {
                $before = RequestScopes::current();
                $before->set($owner, $value);
                \Fiber::suspend();

                // Resolve the scope again after the other request has entered.
                $after = RequestScopes::current();

                return [$before, $after, $after->get($owner, static fn (): string => 'unset')];
            };
        };

        $a = RequestFiber::start($body('a'));
        $b = RequestFiber::start($body('b'));

        $a->resume();
        $b->resume();

        [$beforeA, $afterA, $valueA] = $a->result();
        [$beforeB, $afterB, $valueB] = $b->result();

        self::assertSame('a', $valueA);
        self::assertSame('b', $valueB);
        self::assertSame($beforeA, $afterA);
        self::assertSame($beforeB, $afterB);
        self::assertNotSame($afterA, $afterB);
    }

    public function test_a_recycled_fiber_starts_its_next_request_with_a_fresh_scope(): void
    {
        $owner = new \stdClass();

        $fiber = new \Fiber(static function () use ($owner): array {
            RequestScopes::enterRequest();
            $first = RequestScopes::current();
            $first->set($owner, 'x');
            RequestScopes::leaveRequest();

            RequestScopes::enterRequest();
            $second = RequestScopes::current();
            $value = $second->get($owner, static fn (): string => 'fresh');
            RequestScopes::leaveRequest();

            return [$first, $second, $value];
        });
        $fiber->start();

        [$first, $second, $value] = $fiber->getReturn();

        self::assertSame('fresh', $value);
        self::assertNotSame($first, $second);
    }

    public function test_outside_any_fiber_in_worker_mode_uses_the_shared_scope(): void
    {
        $shared = RequestScopes::current();

        $request = RequestFiber::start(static function (): void {
            \Fiber::suspend();
        });

        self::assertSame($shared, RequestScopes::current());

        $request->resume();
    }

    public function test_a_fiber_the_app_creates_inside_a_request_gets_a_private_empty_scope(): void
    {
        $owner = new \stdClass();

        $request = RequestFiber::start(static function () use ($owner): array {
            $requestScope = RequestScopes::current();
            $requestScope->set($owner, 'mine');

            $inUserFiber = static function () use ($owner): array {
                $scope = RequestScopes::current();

                return [
                    $scope,
                    $scope->get($owner, static fn (): string => 'empty'),
                    RequestScopes::current() === $scope,
                ];
            };

            $first = new \Fiber($inUserFiber);
            $first->start();
            $second = new \Fiber($inUserFiber);
            $second->start();

            return [$requestScope, $first->getReturn(), $second->getReturn()];
        });

        [$requestScope, [$firstScope, $firstValue, $firstStable], [$secondScope, $secondValue, $secondStable]] = $request->result();

        self::assertSame('empty', $firstValue);
        self::assertSame('empty', $secondValue);
        self::assertTrue($firstStable);
        self::assertTrue($secondStable);
        self::assertNotSame($requestScope, $firstScope);
        self::assertNotSame($requestScope, $secondScope);
        self::assertNotSame($firstScope, $secondScope);
    }

    public function test_in_flight_counts_open_requests(): void
    {
        $suspend = static function (): void {
            \Fiber::suspend();
        };

        self::assertSame(0, RequestScopes::inFlight());

        $a = RequestFiber::start($suspend);
        $b = RequestFiber::start($suspend);

        self::assertSame(2, RequestScopes::inFlight());

        $a->resume();

        self::assertSame(1, RequestScopes::inFlight());

        $b->resume();

        self::assertSame(0, RequestScopes::inFlight());
    }

    public function test_enter_request_outside_a_fiber_marks_the_worker_degraded(): void
    {
        self::assertFalse(RequestScopes::isDegraded());

        RequestScopes::enterRequest();

        self::assertTrue(RequestScopes::isDegraded());
        self::assertSame(1, RequestScopes::inFlight());

        RequestScopes::leaveRequest();

        self::assertSame(0, RequestScopes::inFlight());
    }

    public function test_reset_for_tests_clears_everything(): void
    {
        RequestScopes::enterRequest();
        $workerShared = RequestScopes::current();

        RequestScopes::resetForTests();

        self::assertSame(0, RequestScopes::inFlight());
        self::assertFalse(RequestScopes::isDegraded());

        $fromMain = RequestScopes::current();
        $fiber = new \Fiber(static fn (): RequestScope => RequestScopes::current());
        $fiber->start();

        self::assertNotSame($workerShared, $fromMain);
        self::assertSame($fromMain, $fiber->getReturn());
    }
}
