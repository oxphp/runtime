<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Symfony;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface as ComponentEventDispatcherInterface;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolverInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Firewall\ContextListener;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @internal A `ContextListener` whose response listener stays registered once added.
 *
 * On the first main request the stock listener adds its own `onKernelResponse` to
 * the shared event dispatcher and sets a private `$registered` flag. The first
 * response that ran the firewall removes the listener and clears the flag. With
 * overlapping requests, A registers and suspends, B skips registering (the flag
 * is set), B's response saves B's token and removes the listener, and A's
 * response then runs no listener: A's login is silently lost.
 *
 * This subclass hands the parent a `PinnedResponseListenerDispatcher` instead of
 * the real dispatcher, so the listener is added once and never removed. The
 * parent's own per-request check (`_security_firewall_run` on the request must
 * match its session key) then decides which responses save a token. Every other
 * dispatcher call is forwarded, so `TokenDeauthenticatedEvent` still dispatches.
 * The listener is added on first use at runtime, which keeps the stock order
 * among the other listeners.
 *
 * The subclass holds no per-request state of its own, so it needs no scope
 * token. The per-request token lives in the (scoped) token storage it is given.
 *
 * Outside worker mode a request registers the listener once and it stays: a
 * response that skipped the firewall saves nothing, so behaviour is stock apart
 * from the listener remaining registered.
 */
final class FiberScopedContextListener extends ContextListener
{
    /**
     * Same signature as `ContextListener::__construct()`.
     *
     * A dispatcher that cannot manage listeners (only the contracts interface)
     * is passed through unchanged: the stock listener could not use it either.
     *
     * @param iterable<mixed, UserProviderInterface> $userProviders
     */
    public function __construct(
        TokenStorageInterface $tokenStorage,
        iterable $userProviders,
        string $contextKey,
        ?LoggerInterface $logger = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?AuthenticationTrustResolverInterface $trustResolver = null,
        ?callable $sessionTrackerEnabler = null,
    ) {
        parent::__construct(
            $tokenStorage,
            $userProviders,
            $contextKey,
            $logger,
            $dispatcher instanceof ComponentEventDispatcherInterface ? new PinnedResponseListenerDispatcher($dispatcher) : $dispatcher,
            $trustResolver,
            $sessionTrackerEnabler,
        );
    }
}
