<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Fiber;

use OxPHP\Runtime\Internal\Fiber\RequestScope;
use OxPHP\Runtime\Internal\Fiber\RequestScopes;
use OxPHP\Runtime\Internal\Fiber\Ticket;
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
            RequestScopes::enterRequest($ticket = new Ticket());
            $first = RequestScopes::current();
            $first->set($owner, 'x');
            RequestScopes::leaveRequest($ticket);

            RequestScopes::enterRequest($ticket = new Ticket());
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

        RequestScopes::enterRequest($ticket = new Ticket());

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

        RequestScopes::enterRequest($ticket = new Ticket()); // from main: no current fiber

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
            RequestScopes::enterRequest($ticket = new Ticket());
            RequestScopes::leaveRequest($ticket);
            RequestScopes::leaveRequest($ticket);
        });
        $fiber->start();

        self::assertSame(1, RequestScopes::inFlight(), 'The other request is still open.');

        $other->resume();

        self::assertSame(0, RequestScopes::inFlight());
    }

    public function test_a_closed_request_keeps_its_scope_for_the_code_that_runs_after_it_on_its_fiber(): void
    {
        $owner = new \stdClass();

        // OxPHP runs a request's shutdown functions on the request's fiber, after
        // its finally (or, after a fatal error, without it).
        $fiber = new \Fiber(static function () use ($owner): array {
            RequestScopes::enterRequest($ticket = new Ticket());
            $requestScope = RequestScopes::current();
            $requestScope->set($owner, 'mine');
            RequestScopes::leaveRequest($ticket);

            $afterwards = RequestScopes::current();

            return [$requestScope, $afterwards, $afterwards->get($owner, static fn (): string => 'lost')];
        });
        $fiber->start();

        [$requestScope, $afterwards, $value] = $fiber->getReturn();

        self::assertSame($requestScope, $afterwards);
        self::assertSame('mine', $value);
    }

    public function test_code_after_one_request_on_a_recycled_fiber_never_reaches_the_next_one(): void
    {
        $owner = new \stdClass();

        // Two requests on one fiber, each with a shutdown function that runs on it
        // after the request closed: the first writes, the second only reads.
        $fiber = new \Fiber(static function () use ($owner): array {
            RequestScopes::enterRequest($ticket = new Ticket());
            RequestScopes::leaveRequest($ticket);
            RequestScopes::current()->set($owner, 'fr'); // the first request's shutdown function

            RequestScopes::enterRequest($ticket = new Ticket());
            $during = RequestScopes::current()->get($owner, static fn (): string => 'unset');
            RequestScopes::leaveRequest($ticket);
            $after = RequestScopes::current()->get($owner, static fn (): string => 'unset'); // the second's

            return [$during, $after];
        });
        $fiber->start();

        self::assertSame(['unset', 'unset'], $fiber->getReturn());
    }

    public function test_a_ticket_still_open_at_shutdown_marks_the_worker_bailed_out(): void
    {
        // The request ends without its finally (a fatal error, a time limit,
        // exit(), a cancel, a client gone mid-stream); its shutdown callback runs.
        $ticket = new Ticket();
        $fiber = new \Fiber(static function () use ($ticket): void {
            RequestScopes::enterRequest($ticket);
            \Fiber::suspend();
        });
        $fiber->start();

        self::assertSame(1, RequestScopes::inFlight());
        self::assertFalse(RequestScopes::hasBailedOut());

        RequestScopes::leaveRequest($ticket, atShutdown: true);

        self::assertSame(0, RequestScopes::inFlight());
        self::assertTrue(RequestScopes::hasBailedOut());

        RequestScopes::leaveRequest($ticket);

        self::assertSame(0, RequestScopes::inFlight(), 'A finally that runs after all closes nothing twice.');
    }

    public function test_a_ticket_closed_by_its_finally_is_not_a_bailout(): void
    {
        $fiber = new \Fiber(static function (): void {
            RequestScopes::enterRequest($ticket = new Ticket());

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
            RequestScopes::enterRequest($old = new Ticket()); // a request that never closed its ticket

            RequestScopes::enterRequest($new = new Ticket()); // the next request on the recycled fiber
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

    public function test_a_ticket_never_entered_closes_nothing(): void
    {
        // A request ended between registering its shutdown close and entering its
        // ticket: the close must neither count it down nor call it a bailout.
        $other = RequestFiber::start(static function (): void {
            \Fiber::suspend();
        });

        RequestScopes::leaveRequest(new Ticket(), atShutdown: true);

        self::assertSame(1, RequestScopes::inFlight());
        self::assertFalse(RequestScopes::hasBailedOut());

        $other->resume();
    }

    public function test_a_ticket_nobody_holds_any_more_is_not_counted(): void
    {
        $fiber = new \Fiber(static function (): void {
            RequestScopes::enterRequest(new Ticket());
        });
        $fiber->start();

        self::assertSame(0, RequestScopes::inFlight(), 'Nothing can close it, so it must not hold the count up.');
    }

    public function test_a_long_lived_fiber_gets_a_private_scope_under_each_request_that_runs_it(): void
    {
        $owner = new \stdClass();

        // A fiber that outlives requests and runs for each, like a scheduler's.
        $shared = new \Fiber(static function () use ($owner): void {
            $request = \Fiber::suspend();

            while (true) {
                $scope = RequestScopes::current();
                $seen = $scope->get($owner, static fn (): string => 'empty');
                $scope->set($owner, $request);

                $nested = new \Fiber(static fn (): RequestScope => RequestScopes::current());
                $nested->start();

                $request = \Fiber::suspend([$seen, $scope, $nested->getReturn()]);
            }
        });
        $shared->start();

        $run = static fn (string $name): RequestFiber => RequestFiber::start(static function () use ($owner, $shared, $name): array {
            RequestScopes::current()->set($owner, 'request ' . $name);
            $first = $shared->resume($name);
            \Fiber::suspend();
            $second = $shared->resume($name);

            return [RequestScopes::current(), $first, $second];
        });

        $a = $run('a');
        $b = $run('b');
        $a->resume();
        $b->resume();

        [$scopeA, [$seenA1, $privateA1, $nestedA1], [$seenA2, $privateA2]] = $a->result();
        [$scopeB, [$seenB1, $privateB1], [$seenB2, $privateB2]] = $b->result();

        self::assertSame(['empty', 'a'], [$seenA1, $seenA2], 'It keeps its own state while it runs for one request.');
        self::assertSame(['empty', 'b'], [$seenB1, $seenB2], 'Never what it kept for the other request.');
        self::assertSame($privateA1, $privateA2);
        self::assertSame($privateB1, $privateB2);
        self::assertNotSame($privateA1, $privateB1);
        self::assertNotSame($scopeA, $privateA1, 'Never the request\'s own state either.');
        self::assertNotSame($privateA1, $nestedA1, 'A fiber it starts gets a private scope of its own.');
        self::assertNotSame($scopeA, $nestedA1);
    }

    public function test_destructors_the_garbage_collector_runs_never_share_state_across_requests(): void
    {
        $owner = new \stdClass();
        $seen = [];

        // PHP 8.4+ runs the destructors a collection finds in a fiber of its own
        // when it collects inside a fiber, and reuses that fiber for the next one.
        $collect = static function (string $request) use ($owner, &$seen): void {
            $cycle = new class ($owner, $request, $seen) {
                public ?object $self = null;

                /** @param list<array{string, string, ?\Fiber}> $seen */
                public function __construct(private readonly object $owner, private readonly string $request, private array &$seen) {}

                public function __destruct()
                {
                    $scope = RequestScopes::current();
                    $this->seen[] = [$this->request, $scope->get($this->owner, static fn (): string => 'empty'), \Fiber::getCurrent()];
                    $scope->set($this->owner, 'set by ' . $this->request);
                }
            };
            $cycle->self = $cycle;
            unset($cycle);
            \gc_collect_cycles();
        };

        $a = RequestFiber::start(static function () use ($owner, $collect): void {
            RequestScopes::current()->set($owner, 'request a');
            $collect('a');
            \Fiber::suspend();
        });
        $b = RequestFiber::start(static function () use ($owner, $collect): void {
            RequestScopes::current()->set($owner, 'request b');
            $collect('b');
        });
        $a->resume();

        self::assertCount(2, $seen);
        [[, $seenByA, $gcFiberA], [, $seenByB, $gcFiberB]] = $seen;
        self::assertNotNull($gcFiberA);
        self::assertSame($gcFiberA, $gcFiberB, 'One collector fiber ran both requests\' destructors.');
        self::assertSame('empty', $seenByA);
        self::assertSame('empty', $seenByB, 'Not what a destructor run for the other request kept.');
    }

    public function test_a_fiber_no_request_is_running_uses_the_shared_scope(): void
    {
        $appFiber = null;
        $request = RequestFiber::start(static function () use (&$appFiber): RequestScope {
            $appFiber = new \Fiber(static function (): RequestScope {
                \Fiber::suspend();

                return RequestScopes::current();
            });
            $appFiber->start();

            return RequestScopes::current();
        });
        \assert($appFiber instanceof \Fiber);

        $appFiber->resume(); // from main, after its request ended

        self::assertSame(RequestScopes::current(), $appFiber->getReturn());
        self::assertNotSame($request->result(), $appFiber->getReturn());
    }

    public function test_reset_for_tests_clears_everything(): void
    {
        RequestScopes::enterRequest($ticket = new Ticket());
        RequestScopes::leaveRequest($ticket, atShutdown: true);
        RequestScopes::enterRequest($open = new Ticket());
        $workerShared = RequestScopes::current();

        self::assertSame(1, RequestScopes::inFlight());

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
