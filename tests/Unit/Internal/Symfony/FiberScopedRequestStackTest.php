<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Symfony;

use OxPHP\Runtime\Internal\Fiber\RequestScopes;
use OxPHP\Runtime\Internal\Symfony\FiberScopedRequestStack;
use OxPHP\Runtime\Tests\Stub\RequestFiber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class FiberScopedRequestStackTest extends TestCase
{
    protected function setUp(): void
    {
        RequestScopes::resetForTests();
    }

    protected function tearDown(): void
    {
        RequestScopes::resetForTests();
    }

    public function test_overlapping_requests_each_see_only_their_own_stack(): void
    {
        $stack = new FiberScopedRequestStack();
        $ra = Request::create('/a');
        $rb = Request::create('/b');

        $a = RequestFiber::start(static function () use ($stack, $ra): array {
            $stack->push($ra);
            \Fiber::suspend();

            // Read through the service again, now that request B has entered.
            return [$stack->getMainRequest(), $stack->getCurrentRequest(), $stack->pop(), $stack->getMainRequest()];
        });

        $b = RequestFiber::start(static function () use ($stack, $rb): array {
            $stack->push($rb);

            return [$stack->getMainRequest(), $stack->getCurrentRequest(), $stack->pop(), $stack->getMainRequest()];
        });

        $a->resume();

        [$mainA, $currentA, $poppedA, $mainAfterPopA] = $a->result();
        [$mainB, $currentB, $poppedB, $mainAfterPopB] = $b->result();

        self::assertSame($rb, $mainB);
        self::assertSame($rb, $currentB);
        self::assertSame($rb, $poppedB);
        self::assertNull($mainAfterPopB);

        self::assertSame($ra, $mainA);
        self::assertSame($ra, $currentA);
        self::assertSame($ra, $poppedA);
        self::assertNull($mainAfterPopA);
    }

    public function test_a_stream_left_open_is_nobodys_main_request(): void
    {
        $stack = new FiberScopedRequestStack();
        $ra = Request::create('/stream');
        $rb = Request::create('/other');

        // A pushes and never pops while suspended, like an open SSE stream.
        $a = RequestFiber::start(static function () use ($stack, $ra): void {
            $stack->push($ra);
            \Fiber::suspend();
        });

        $b = RequestFiber::start(static function () use ($stack, $rb): array {
            $stack->push($rb);

            return [$stack->getMainRequest(), $stack->getParentRequest()];
        });

        [$main, $parent] = $b->result();

        self::assertSame($rb, $main);
        self::assertNull($parent);

        $a->resume();
    }

    public function test_sub_requests_keep_stock_parent_and_main_semantics(): void
    {
        $stack = new FiberScopedRequestStack();
        $main = Request::create('/main');
        $sub = Request::create('/sub');

        $request = RequestFiber::start(static function () use ($stack, $main, $sub): array {
            $stack->push($main);
            $stack->push($sub);

            return [$stack->getParentRequest(), $stack->getMainRequest(), $stack->getCurrentRequest()];
        });

        [$parent, $mainRequest, $current] = $request->result();

        self::assertSame($main, $parent);
        self::assertSame($main, $mainRequest);
        self::assertSame($sub, $current);
    }

    public function test_get_session_reads_the_scopes_current_request(): void
    {
        $stack = new FiberScopedRequestStack();
        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/session');
        $request->setSession($session);

        $withSession = RequestFiber::start(static function () use ($stack, $request): mixed {
            $stack->push($request);

            return $stack->getSession();
        });

        self::assertSame($session, $withSession->result());

        $this->expectException(SessionNotFoundException::class);

        RequestFiber::start(static fn (): mixed => $stack->getSession());
    }

    public function test_outside_worker_mode_it_is_one_shared_stack(): void
    {
        $stack = new FiberScopedRequestStack();
        $request = Request::create('/shared');

        $stack->push($request);

        $fiber = new \Fiber(static fn (): ?Request => $stack->getCurrentRequest());
        $fiber->start();

        self::assertSame($request, $fiber->getReturn());
    }

    public function test_a_clone_keeps_the_requests_and_is_independent_outside_worker_mode(): void
    {
        $stack = new FiberScopedRequestStack();
        $request = Request::create('/main');
        $extra = Request::create('/extra');

        [$drained, $originalAfterDrain, $cloneAfterDrain, $originalAfterClonePush] = self::cloneAndDrain($stack, $request, $extra);

        self::assertSame([$request], $drained);
        self::assertSame($request, $originalAfterDrain);
        self::assertNull($cloneAfterDrain);
        self::assertSame($request, $originalAfterClonePush);
    }

    public function test_a_clone_keeps_the_requests_and_is_independent_inside_a_request_scope(): void
    {
        $stack = new FiberScopedRequestStack();
        $request = Request::create('/main');
        $extra = Request::create('/extra');

        $inRequest = RequestFiber::start(static fn (): array => self::cloneAndDrain($stack, $request, $extra));

        [$drained, $originalAfterDrain, $cloneAfterDrain, $originalAfterClonePush] = $inRequest->result();

        self::assertSame([$request], $drained);
        self::assertSame($request, $originalAfterDrain);
        self::assertNull($cloneAfterDrain);
        self::assertSame($request, $originalAfterClonePush);
    }

    /**
     * Mirrors `AbstractSessionListener`: clone the stack, then pop the clone until empty.
     *
     * @return array{list<Request>, ?Request, ?Request, ?Request}
     */
    private static function cloneAndDrain(FiberScopedRequestStack $stack, Request $request, Request $extra): array
    {
        $stack->push($request);

        $clone = clone $stack;

        $drained = [];
        while (($popped = $clone->pop()) !== null) {
            $drained[] = $popped;
        }

        $originalAfterDrain = $stack->getCurrentRequest();
        $cloneAfterDrain = $clone->getCurrentRequest();

        // Pushing onto the clone must not show up in the original either.
        $clone->push($extra);

        return [$drained, $originalAfterDrain, $cloneAfterDrain, $stack->getCurrentRequest()];
    }
}
