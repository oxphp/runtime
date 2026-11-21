<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Symfony;

use OxPHP\Runtime\Internal\Fiber\RequestScopes;
use OxPHP\Runtime\Internal\Symfony\FiberScopedContextListener;
use OxPHP\Runtime\Internal\Symfony\FiberScopedTokenStorage;
use OxPHP\Runtime\Internal\Symfony\PinnedResponseListenerDispatcher;
use OxPHP\Runtime\Tests\Stub\Apps\FakeSymfonyKernel;
use OxPHP\Runtime\Tests\Stub\RequestFiber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\InMemoryUserProvider;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface as ContractsEventDispatcherInterface;

final class FiberScopedContextListenerTest extends TestCase
{
    private EventDispatcher $dispatcher;

    private FiberScopedTokenStorage $storage;

    private FiberScopedContextListener $listener;

    private HttpKernelInterface $kernel;

    protected function setUp(): void
    {
        RequestScopes::resetForTests();

        $this->dispatcher = new EventDispatcher();
        $this->storage = new FiberScopedTokenStorage();
        $this->listener = new FiberScopedContextListener(
            $this->storage,
            [new InMemoryUserProvider(['alice' => ['password' => 'secret'], 'bob' => ['password' => 'secret']])],
            'main',
            null,
            $this->dispatcher,
        );
        $this->kernel = FakeSymfonyKernel::echoingPath();
    }

    protected function tearDown(): void
    {
        RequestScopes::resetForTests();
    }

    public function test_a_login_persists_when_another_request_on_the_firewall_finishes_first(): void
    {
        $requestA = self::request();
        $requestB = self::request();

        $a = RequestFiber::start(function () use ($requestA): void {
            $this->authenticate($requestA);
            $this->storage->setToken(self::token('alice'));
            \Fiber::suspend();

            // Request B has finished by now; A's own response must still save A's login.
            $this->respond($requestA);
        });

        RequestFiber::start(function () use ($requestB): void {
            $this->authenticate($requestB);
            $this->storage->setToken(self::token('bob'));
            $this->respond($requestB);
        });

        $a->resume();

        self::assertSame('alice', self::savedUser($requestA));
        self::assertSame('bob', self::savedUser($requestB));
    }

    public function test_the_response_listener_is_registered_once(): void
    {
        self::assertCount(0, $this->dispatcher->getListeners(KernelEvents::RESPONSE));

        $requestA = self::request();
        $requestB = self::request();
        $requestC = self::request();

        $a = RequestFiber::start(function () use ($requestA): void {
            $this->authenticate($requestA);
            $this->storage->setToken(self::token('alice'));
            \Fiber::suspend();
            $this->respond($requestA);
        });

        RequestFiber::start(function () use ($requestB): void {
            $this->authenticate($requestB);
            $this->respond($requestB);
        });

        $a->resume();

        self::assertCount(1, $this->dispatcher->getListeners(KernelEvents::RESPONSE));

        // The parent's own registered flag was cleared by the responses above, so a
        // later request asks to register again; that must not add a second listener.
        RequestFiber::start(function () use ($requestC): void {
            $this->authenticate($requestC);
            $this->respond($requestC);
        });

        self::assertCount(1, $this->dispatcher->getListeners(KernelEvents::RESPONSE));
    }

    public function test_responses_of_requests_that_skipped_the_firewall_save_nothing(): void
    {
        $firewallRequest = self::request();
        $otherRequest = self::request();

        // One request runs the firewall, which registers the response listener.
        RequestFiber::start(function () use ($firewallRequest): void {
            $this->authenticate($firewallRequest);
        });

        // Another one never reaches the firewall (e.g. a public asset), though a
        // token is somehow present in its scope.
        RequestFiber::start(function () use ($otherRequest): void {
            $this->storage->setToken(self::token('bob'));
            $this->respond($otherRequest);
        });

        self::assertFalse($otherRequest->getSession()->has('_security_main'));
    }

    public function test_without_a_dispatcher_it_still_authenticates(): void
    {
        $listener = new FiberScopedContextListener($this->storage, [], 'main');
        $request = self::request();

        $request1 = RequestFiber::start(function () use ($listener, $request): ?TokenInterface {
            $this->storage->setToken(self::token('alice'));
            $listener->authenticate(new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST));

            return $this->storage->getToken();
        });

        self::assertNull($request1->result());
        self::assertSame('_security_main', $request->attributes->get('_security_firewall_run'));
    }

    public function test_a_dispatcher_without_listener_management_is_handed_on_unchanged(): void
    {
        $contractsOnly = new class () implements ContractsEventDispatcherInterface {
            public function dispatch(object $event, ?string $eventName = null): object
            {
                return $event;
            }
        };

        // Must not blow up at construction; stock would only fail later, on addListener().
        $listener = new FiberScopedContextListener($this->storage, [], 'main', null, $contractsOnly);

        self::assertInstanceOf(FiberScopedContextListener::class, $listener);
    }

    public function test_forwards_everything_but_response_listener_churn(): void
    {
        $inner = new EventDispatcher();
        $pinned = new PinnedResponseListenerDispatcher($inner);

        // Other events: add, dispatch, priority, query and remove all reach the inner.
        $calls = [];
        $listener = static function () use (&$calls): void {
            $calls[] = 'custom';
        };

        $pinned->addListener('custom.event', $listener, 3);

        self::assertSame([$listener], $inner->getListeners('custom.event'));
        self::assertSame([$listener], $pinned->getListeners('custom.event'));
        self::assertSame(3, $pinned->getListenerPriority('custom.event', $listener));
        self::assertTrue($pinned->hasListeners('custom.event'));

        $event = new \stdClass();
        self::assertSame($event, $pinned->dispatch($event, 'custom.event'));
        self::assertSame(['custom'], $calls);

        $pinned->removeListener('custom.event', $listener);

        self::assertSame([], $inner->getListeners('custom.event'));

        // Subscribers are forwarded too.
        $subscriber = new class () implements EventSubscriberInterface {
            public int $seen = 0;

            public static function getSubscribedEvents(): array
            {
                return ['subscribed.event' => 'onEvent'];
            }

            public function onEvent(): void
            {
                ++$this->seen;
            }
        };

        $pinned->addSubscriber($subscriber);
        $pinned->dispatch(new \stdClass(), 'subscribed.event');

        self::assertSame(1, $subscriber->seen);

        $pinned->removeSubscriber($subscriber);

        self::assertFalse($inner->hasListeners('subscribed.event'));

        // The response event: the first add goes through, later adds and every removal do not.
        $first = static function (): void {};
        $second = static function (): void {};

        $pinned->addListener(KernelEvents::RESPONSE, $first, 5);
        $pinned->addListener(KernelEvents::RESPONSE, $second);

        self::assertSame([$first], $inner->getListeners(KernelEvents::RESPONSE));
        self::assertSame(5, $inner->getListenerPriority(KernelEvents::RESPONSE, $first));

        $pinned->removeListener(KernelEvents::RESPONSE, $first);

        self::assertSame([$first], $inner->getListeners(KernelEvents::RESPONSE));
    }

    private function authenticate(Request $request): void
    {
        $this->listener->authenticate(new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST));
    }

    private function respond(Request $request): void
    {
        $this->dispatcher->dispatch(
            new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response()),
            KernelEvents::RESPONSE,
        );
    }

    private static function request(): Request
    {
        $request = Request::create('/');
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private static function token(string $username): UsernamePasswordToken
    {
        return new UsernamePasswordToken(new InMemoryUser($username, 'secret'), 'main', ['ROLE_USER']);
    }

    /**
     * Identifier of the user whose token was saved to the request's session, or null if none was.
     */
    private static function savedUser(Request $request): ?string
    {
        $session = $request->getSession();

        if (!$session->has('_security_main')) {
            return null;
        }

        $token = \unserialize($session->get('_security_main'));
        \assert($token instanceof TokenInterface);

        return $token->getUserIdentifier();
    }
}
