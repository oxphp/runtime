<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Symfony;

use OxPHP\Runtime\Internal\Fiber\RequestScopes;
use OxPHP\Runtime\Internal\Fiber\Ticket;
use OxPHP\Runtime\Internal\Symfony\FiberScopedTokenStorage;
use OxPHP\Runtime\Tests\Stub\Apps\FakeSymfonyKernel;
use OxPHP\Runtime\Tests\Stub\RequestFiber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\InMemoryUserProvider;
use Symfony\Component\Security\Http\Firewall\ContextListener;

final class FiberScopedTokenStorageTest extends TestCase
{
    protected function setUp(): void
    {
        RequestScopes::resetForTests();
    }

    protected function tearDown(): void
    {
        RequestScopes::resetForTests();
    }

    public function test_it_is_still_a_token_storage(): void
    {
        self::assertInstanceOf(TokenStorage::class, new FiberScopedTokenStorage());
    }

    public function test_overlapping_requests_keep_their_own_token(): void
    {
        $storage = new FiberScopedTokenStorage();
        $alice = self::token('alice');
        $bob = self::token('bob');

        $a = RequestFiber::start(static function () use ($storage, $alice): ?TokenInterface {
            $storage->setToken($alice);
            \Fiber::suspend();

            // Read through the service again, now that request B has entered.
            return $storage->getToken();
        });

        $b = RequestFiber::start(static function () use ($storage, $bob): ?TokenInterface {
            $storage->setToken($bob);

            return $storage->getToken();
        });

        $a->resume();

        self::assertSame($bob, $b->result());
        self::assertSame($alice, $a->result());
    }

    public function test_reset_clears_only_the_callers_token(): void
    {
        $storage = new FiberScopedTokenStorage();
        $alice = self::token('alice');
        $bob = self::token('bob');

        $a = RequestFiber::start(static function () use ($storage, $alice): ?TokenInterface {
            $storage->setToken($alice);
            \Fiber::suspend();

            return $storage->getToken();
        });

        $b = RequestFiber::start(static function () use ($storage, $bob): ?TokenInterface {
            $storage->setToken($bob);
            $storage->reset();

            return $storage->getToken();
        });

        $a->resume();

        self::assertNull($b->result());
        self::assertSame($alice, $a->result());
    }

    public function test_next_request_on_a_recycled_fiber_has_no_token(): void
    {
        $storage = new FiberScopedTokenStorage();
        $alice = self::token('alice');

        $fiber = new \Fiber(static function () use ($storage, $alice): ?TokenInterface {
            RequestScopes::enterRequest($ticket = new Ticket());
            $storage->setToken($alice);
            RequestScopes::leaveRequest($ticket);

            RequestScopes::enterRequest($ticket = new Ticket());
            $token = $storage->getToken();
            RequestScopes::leaveRequest($ticket);

            return $token;
        });
        $fiber->start();

        self::assertNull($fiber->getReturn());
    }

    public function test_a_recycled_fiber_that_never_left_does_not_inherit_the_token_either(): void
    {
        $storage = new FiberScopedTokenStorage();
        $alice = self::token('alice');

        $fiber = new \Fiber(static function () use ($storage, $alice): ?TokenInterface {
            RequestScopes::enterRequest($first = new Ticket());
            $storage->setToken($alice);

            // OxPHP reuses the fiber for the next request without a separate leave.
            RequestScopes::enterRequest($second = new Ticket());

            return $storage->getToken();
        });
        $fiber->start();

        self::assertNull($fiber->getReturn());
    }

    public function test_initializer_runs_per_scope(): void
    {
        $storage = new FiberScopedTokenStorage();
        $alice = self::token('alice');
        $runs = 0;

        $a = RequestFiber::start(static function () use ($storage, $alice, &$runs): ?TokenInterface {
            $storage->setInitializer(static function () use ($storage, $alice, &$runs): void {
                ++$runs;
                $storage->setToken($alice);
            });
            \Fiber::suspend();

            return $storage->getToken();
        });

        // Request B never set an initializer, so reading its token must not run A's.
        $b = RequestFiber::start(static fn (): ?TokenInterface => $storage->getToken());

        self::assertNull($b->result());
        self::assertSame(0, $runs);

        $a->resume();

        self::assertSame($alice, $a->result());
        self::assertSame(1, $runs);
    }

    public function test_outside_worker_mode_it_is_one_shared_token_even_inside_a_user_fiber(): void
    {
        $storage = new FiberScopedTokenStorage();
        $alice = self::token('alice');

        $storage->setToken($alice);

        $fiber = new \Fiber(static fn (): ?TokenInterface => $storage->getToken());
        $fiber->start();

        self::assertSame($alice, $fiber->getReturn());
    }

    public function test_a_clone_keeps_the_token_and_is_independent_inside_a_request_scope(): void
    {
        $storage = new FiberScopedTokenStorage();
        $alice = self::token('alice');

        $request = RequestFiber::start(static function () use ($storage, $alice): array {
            $storage->setToken($alice);

            $clone = clone $storage;
            $clonedToken = $clone->getToken();

            $clone->reset();

            return [$clonedToken, $clone->getToken(), $storage->getToken()];
        });

        [$clonedToken, $cloneAfterReset, $originalAfterCloneReset] = $request->result();

        self::assertSame($alice, $clonedToken);
        self::assertNull($cloneAfterReset);
        self::assertSame($alice, $originalAfterCloneReset);
    }

    /**
     * Pins the real-world login path with the stock `ContextListener`, wired the way
     * SecurityBundle wires it: the listener's own dispatcher is the per-firewall one,
     * and its `onKernelResponse` is registered statically on the global dispatcher.
     * Its dynamic add/remove of a response listener therefore never touches the
     * global dispatcher, so the scoped token storage alone keeps logins intact.
     */
    public function test_a_login_persists_when_another_request_on_the_firewall_finishes_first(): void
    {
        $storage = new FiberScopedTokenStorage();
        $firewallDispatcher = new EventDispatcher();
        $globalDispatcher = new EventDispatcher();
        $listener = new ContextListener(
            $storage,
            [new InMemoryUserProvider(['alice' => ['password' => 'secret'], 'bob' => ['password' => 'secret']])],
            'main',
            null,
            $firewallDispatcher,
        );
        $globalDispatcher->addListener(KernelEvents::RESPONSE, $listener->onKernelResponse(...));

        $kernel = FakeSymfonyKernel::echoingPath();
        $sessionA = new Session(new MockArraySessionStorage());
        $sessionB = new Session(new MockArraySessionStorage());
        $requestA = Request::create('/');
        $requestA->setSession($sessionA);
        $requestB = Request::create('/');
        $requestB->setSession($sessionB);
        $alice = self::token('alice');

        $a = RequestFiber::start(static function () use ($storage, $listener, $globalDispatcher, $kernel, $requestA, $alice): void {
            $listener->authenticate(new RequestEvent($kernel, $requestA, HttpKernelInterface::MAIN_REQUEST));
            $storage->setToken($alice);
            \Fiber::suspend();

            // Request B has finished by now; A's response must still save A's own login.
            $globalDispatcher->dispatch(
                new ResponseEvent($kernel, $requestA, HttpKernelInterface::MAIN_REQUEST, new Response()),
                KernelEvents::RESPONSE,
            );
        });

        RequestFiber::start(static function () use ($listener, $globalDispatcher, $kernel, $requestB): void {
            $listener->authenticate(new RequestEvent($kernel, $requestB, HttpKernelInterface::MAIN_REQUEST));
            $globalDispatcher->dispatch(
                new ResponseEvent($kernel, $requestB, HttpKernelInterface::MAIN_REQUEST, new Response()),
                KernelEvents::RESPONSE,
            );
        });

        $a->resume();

        self::assertTrue($sessionA->has('_security_main'));
        $saved = \unserialize($sessionA->get('_security_main'));
        self::assertInstanceOf(TokenInterface::class, $saved);
        self::assertSame('alice', $saved->getUserIdentifier());
        self::assertFalse($sessionB->has('_security_main'));
    }

    private static function token(string $username): UsernamePasswordToken
    {
        return new UsernamePasswordToken(new InMemoryUser($username, 'secret'), 'main', ['ROLE_USER']);
    }
}
