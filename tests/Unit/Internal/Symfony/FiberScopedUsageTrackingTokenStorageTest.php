<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Symfony;

use OxPHP\Runtime\Internal\Fiber\RequestScopes;
use OxPHP\Runtime\Internal\Fiber\Ticket;
use OxPHP\Runtime\Internal\Symfony\FiberScopedRequestStack;
use OxPHP\Runtime\Internal\Symfony\FiberScopedTokenStorage;
use OxPHP\Runtime\Internal\Symfony\FiberScopedUsageTrackingTokenStorage;
use OxPHP\Runtime\Tests\Stub\RequestFiber;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\UsageTrackingTokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Contracts\Service\ServiceSubscriberInterface;

final class FiberScopedUsageTrackingTokenStorageTest extends TestCase
{
    protected function setUp(): void
    {
        RequestScopes::resetForTests();
    }

    protected function tearDown(): void
    {
        RequestScopes::resetForTests();
    }

    public function test_it_declares_the_same_subscribed_services_as_the_stock_class(): void
    {
        self::assertInstanceOf(ServiceSubscriberInterface::class, self::storage(new FiberScopedRequestStack()));
        self::assertSame(UsageTrackingTokenStorage::getSubscribedServices(), FiberScopedUsageTrackingTokenStorage::getSubscribedServices());
    }

    public function test_tracking_enabled_by_one_request_does_not_leak_into_an_overlapping_stateless_one(): void
    {
        $requests = new FiberScopedRequestStack();
        $storage = self::storage($requests);
        $usageA = 0;
        $usageB = 0;
        $requestA = self::requestWithSession($usageA);
        $requestB = self::requestWithSession($usageB);
        $alice = self::token('alice');
        $bob = self::token('bob');

        // A is a stateful firewall request: the context listener enables tracking.
        $a = RequestFiber::start(static function () use ($requests, $storage, $requestA, $alice): ?TokenInterface {
            $requests->push($requestA);
            $storage->enableUsageTracking();
            $storage->setToken($alice);
            \Fiber::suspend();

            // Read through the service again, now that request B has entered.
            return $storage->getToken();
        });

        // B is stateless: nothing enables tracking, so reading the token must not touch its session.
        $b = RequestFiber::start(static function () use ($requests, $storage, $requestB, $bob): ?TokenInterface {
            $requests->push($requestB);
            $storage->setToken($bob);

            return $storage->getToken();
        });

        self::assertSame($bob, $b->result());
        self::assertSame(0, $usageB);
        self::assertSame(1, $usageA, 'A has only recorded its own setToken() so far');

        $a->resume();

        self::assertSame($alice, $a->result());
        self::assertSame(2, $usageA);
        self::assertSame(0, $usageB);
    }

    public function test_outside_worker_mode_it_behaves_like_the_stock_class(): void
    {
        $requests = new FiberScopedRequestStack();
        $storage = self::storage($requests);
        $usage = 0;
        $requests->push(self::requestWithSession($usage));

        $storage->getToken();
        self::assertSame(0, $usage, 'tracking is off until a firewall enables it');

        $storage->enableUsageTracking();
        $storage->getToken();
        self::assertSame(1, $usage);

        // `kernel.reset` calls this, then `setToken()` with no token.
        $storage->disableUsageTracking();
        $storage->setToken();
        $storage->getToken();
        self::assertSame(1, $usage);
    }

    public function test_next_request_on_a_recycled_fiber_starts_with_tracking_off(): void
    {
        $requests = new FiberScopedRequestStack();
        $storage = self::storage($requests);
        $usageFirst = 0;
        $usageSecond = 0;
        $first = self::requestWithSession($usageFirst);
        $second = self::requestWithSession($usageSecond);

        $fiber = new \Fiber(static function () use ($requests, $storage, $first, $second): void {
            RequestScopes::enterRequest($ticket = new Ticket());
            $requests->push($first);
            $storage->enableUsageTracking();
            $storage->getToken();
            RequestScopes::leaveRequest($ticket);

            RequestScopes::enterRequest($ticket = new Ticket());
            $requests->push($second);
            $storage->getToken();
            RequestScopes::leaveRequest($ticket);
        });
        $fiber->start();

        self::assertSame(1, $usageFirst);
        self::assertSame(0, $usageSecond);
    }

    public function test_a_clone_keeps_the_tracking_state_and_is_independent_inside_a_request_scope(): void
    {
        $requests = new FiberScopedRequestStack();
        $storage = self::storage($requests);
        $usage = 0;
        $request = self::requestWithSession($usage);

        $inRequest = RequestFiber::start(static function () use ($requests, $storage, $request, &$usage): array {
            $requests->push($request);
            $storage->enableUsageTracking();

            $clone = clone $storage;
            $clone->getToken();
            $afterCloneRead = $usage;

            $clone->disableUsageTracking();
            $clone->getToken();
            $afterCloneDisabled = $usage;

            $storage->getToken();

            return [$afterCloneRead, $afterCloneDisabled, $usage];
        });

        // The clone inherited "on", then switching it off did not switch the original off.
        self::assertSame([1, 1, 2], $inRequest->result());
    }

    private static function storage(FiberScopedRequestStack $requests): FiberScopedUsageTrackingTokenStorage
    {
        $container = new class ($requests) implements ContainerInterface {
            public function __construct(private readonly FiberScopedRequestStack $requests) {}

            public function get(string $id): mixed
            {
                return $id === 'request_stack' ? $this->requests : throw new \LogicException('Unknown service ' . $id);
            }

            public function has(string $id): bool
            {
                return $id === 'request_stack';
            }
        };

        return new FiberScopedUsageTrackingTokenStorage(new FiberScopedTokenStorage(), $container);
    }

    /**
     * A main request whose session adds one to `$usage` each time the session's usage is reported.
     */
    private static function requestWithSession(int &$usage): Request
    {
        $request = Request::create('/');
        $request->setSession(new Session(new MockArraySessionStorage(), null, null, static function () use (&$usage): void {
            ++$usage;
        }));

        return $request;
    }

    private static function token(string $username): UsernamePasswordToken
    {
        return new UsernamePasswordToken(new InMemoryUser($username, 'secret'), 'main', ['ROLE_USER']);
    }
}
