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
            $ticket = RequestScopes::enterRequest();
            $first = RequestScopes::current();
            $first->set($owner, 'x');
            RequestScopes::leaveRequest($ticket);

            $ticket = RequestScopes::enterRequest();
            $second = RequestScopes::current();
            $value = $second->get($owner, static fn (): string => 'fresh');
            RequestScopes::leaveRequest($ticket);

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

        $ticket = RequestScopes::enterRequest();

        self::assertTrue(RequestScopes::isDegraded());
        self::assertSame(1, RequestScopes::inFlight());

        RequestScopes::leaveRequest($ticket);

        self::assertSame(0, RequestScopes::inFlight());
    }

    public function test_a_degraded_ticket_closes_once_like_any_other(): void
    {
        $other = RequestFiber::start(static function (): void {
            \Fiber::suspend();
        });

        $ticket = RequestScopes::enterRequest(); // from main: no current fiber

        self::assertTrue(RequestScopes::isDegraded());
        self::assertSame(2, RequestScopes::inFlight());

        RequestScopes::leaveRequest($ticket);
        RequestScopes::leaveRequest($ticket, atShutdown: true);

        self::assertSame(1, RequestScopes::inFlight());
        self::assertFalse(RequestScopes::hasBailedOut(), 'It was closed before its shutdown callback ran.');

        $other->resume();

        self::assertSame(0, RequestScopes::inFlight());
    }

    public function test_leaving_twice_with_one_ticket_counts_once(): void
    {
        $other = RequestFiber::start(static function (): void {
            \Fiber::suspend();
        });

        $fiber = new \Fiber(static function (): void {
            $ticket = RequestScopes::enterRequest();
            RequestScopes::leaveRequest($ticket);
            RequestScopes::leaveRequest($ticket);
        });
        $fiber->start();

        self::assertSame(1, RequestScopes::inFlight(), 'The other request is still open.');

        $other->resume();

        self::assertSame(0, RequestScopes::inFlight());
    }

    public function test_leaving_unmaps_the_fiber(): void
    {
        $fiber = new \Fiber(static function (): array {
            $ticket = RequestScopes::enterRequest();
            $requestScope = RequestScopes::current();
            RequestScopes::leaveRequest($ticket);

            return [$requestScope, RequestScopes::current()];
        });
        $fiber->start();

        [$requestScope, $afterLeave] = $fiber->getReturn();

        self::assertNotSame($requestScope, $afterLeave, 'A closed request no longer resolves to its scope.');
        self::assertNotSame(RequestScopes::current(), $afterLeave, 'Nor to the shared one: it fails closed.');
    }

    public function test_a_ticket_still_open_at_shutdown_marks_the_worker_bailed_out(): void
    {
        // The request ends without its finally (a fatal error, a time limit,
        // exit(), a cancel, a client gone mid-stream); its shutdown callback runs.
        $ticket = null;
        $fiber = new \Fiber(static function () use (&$ticket): void {
            $ticket = RequestScopes::enterRequest();
            \Fiber::suspend();
        });
        $fiber->start();

        self::assertSame(1, RequestScopes::inFlight());
        self::assertFalse(RequestScopes::hasBailedOut());

        \assert($ticket !== null);
        RequestScopes::leaveRequest($ticket, atShutdown: true);

        self::assertSame(0, RequestScopes::inFlight());
        self::assertTrue(RequestScopes::hasBailedOut());

        RequestScopes::leaveRequest($ticket);

        self::assertSame(0, RequestScopes::inFlight(), 'A finally that runs after all closes nothing twice.');
    }

    public function test_a_ticket_closed_by_its_finally_is_not_a_bailout(): void
    {
        $fiber = new \Fiber(static function (): void {
            $ticket = RequestScopes::enterRequest();

            try {
                // the request's work
            } finally {
                RequestScopes::leaveRequest($ticket);
            }

            // Its shutdown callback runs at the end of the request.
            RequestScopes::leaveRequest($ticket, atShutdown: true);
        });
        $fiber->start();

        self::assertSame(0, RequestScopes::inFlight());
        self::assertFalse(RequestScopes::hasBailedOut());
    }

    public function test_a_late_close_of_an_old_ticket_keeps_the_recycled_fibers_new_scope(): void
    {
        $owner = new \stdClass();

        $fiber = new \Fiber(static function () use ($owner): array {
            $old = RequestScopes::enterRequest(); // a request that never closed its ticket

            $new = RequestScopes::enterRequest(); // the next request on the recycled fiber
            $newScope = RequestScopes::current();
            $newScope->set($owner, 'new');

            RequestScopes::leaveRequest($old, atShutdown: true);

            $stillNew = RequestScopes::current();
            $inFlight = RequestScopes::inFlight();

            RequestScopes::leaveRequest($new);

            return [$newScope, $stillNew, $stillNew->get($owner, static fn (): string => 'lost'), $inFlight];
        });
        $fiber->start();

        [$newScope, $stillNew, $value, $inFlight] = $fiber->getReturn();

        self::assertSame($newScope, $stillNew);
        self::assertSame('new', $value);
        self::assertSame(1, $inFlight);
        self::assertSame(0, RequestScopes::inFlight());
    }

    public function test_reset_for_tests_clears_everything(): void
    {
        $ticket = RequestScopes::enterRequest();
        RequestScopes::leaveRequest($ticket, atShutdown: true);
        RequestScopes::enterRequest();
        $workerShared = RequestScopes::current();

        self::assertTrue(RequestScopes::hasBailedOut());

        RequestScopes::resetForTests();

        self::assertSame(0, RequestScopes::inFlight());
        self::assertFalse(RequestScopes::isDegraded());
        self::assertFalse(RequestScopes::hasBailedOut());

        $fromMain = RequestScopes::current();
        $fiber = new \Fiber(static fn (): RequestScope => RequestScopes::current());
        $fiber->start();

        self::assertNotSame($workerShared, $fromMain);
        self::assertSame($fromMain, $fiber->getReturn());
    }
}
