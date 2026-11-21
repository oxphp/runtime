<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Symfony;

use OxPHP\Runtime\Internal\Fiber\RequestScopes;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * @internal A `TokenStorage` that keeps one stock storage per request scope.
 *
 * It stays a `TokenStorage` subclass because SecurityBundle's `LazyFirewallContext`
 * type-hints the class. Every public method delegates to the storage held by the
 * caller's scope, so one request never reads (or resets) another request's token,
 * and a lazy initializer set by one request never runs for another.
 *
 * Per-scope state is keyed by a private token object, not by `$this`, so
 * `clone $storage` can mint a new token and give the clone a copy of the current
 * scope's storage, as `FiberScopedRequestStack` does.
 *
 * `UsageTrackingTokenStorage`, which wraps this service, keeps its usage-tracking
 * flag shared. Every request's firewall enables it, so the shared flag is harmless.
 *
 * Outside worker mode there is a single shared scope, so this behaves exactly
 * like a stock `TokenStorage`.
 */
final class FiberScopedTokenStorage extends TokenStorage
{
    private object $token;

    public function __construct()
    {
        $this->token = new \stdClass();
    }

    /**
     * The clone gets its own token and a copy of the current scope's storage, so
     * changing one never changes the other.
     */
    public function __clone()
    {
        $inner = $this->inner();

        $this->token = new \stdClass();

        RequestScopes::current()->set($this->token, clone $inner);
    }

    public function getToken(): ?TokenInterface
    {
        return $this->inner()->getToken();
    }

    public function setToken(?TokenInterface $token): void
    {
        $this->inner()->setToken($token);
    }

    public function setInitializer(?callable $initializer): void
    {
        $this->inner()->setInitializer($initializer);
    }

    public function reset(): void
    {
        $this->inner()->reset();
    }

    private function inner(): TokenStorage
    {
        $inner = RequestScopes::current()->get($this->token, static fn (): TokenStorage => new TokenStorage());
        \assert($inner instanceof TokenStorage);

        return $inner;
    }
}
