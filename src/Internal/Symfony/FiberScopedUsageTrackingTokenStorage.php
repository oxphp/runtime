<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Symfony;

use OxPHP\Runtime\Internal\Fiber\RequestScopes;
use Psr\Container\ContainerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\UsageTrackingTokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Contracts\Service\ServiceSubscriberInterface;

/**
 * @internal A `UsageTrackingTokenStorage` that keeps one stock instance per request scope.
 *
 * The stock class (`security.token_storage` in SecurityBundle) is final and holds
 * a private flag that makes `getToken()` and `setToken()` report session usage.
 * Only `ContextListener` turns it on (stateful firewalls), and only `kernel.reset`
 * turns it off, which the guarded resetter skips while requests overlap. A
 * stateless request overlapping a stateful one would therefore see tracking on:
 * its `getToken()` would touch the session metadata bag and report usage, which
 * throws `UnexpectedSessionUsageException` in debug and forces the response
 * private in production. Here each request scope gets its own flag.
 *
 * It cannot extend the final class, so it implements the same two interfaces and
 * the same constructor, and delegates every public instance method to the stock
 * instance held by the caller's scope. The wrapped storage and the container are
 * shared; both are themselves scoped (`FiberScopedTokenStorage`,
 * `FiberScopedRequestStack`) or stateless.
 *
 * Per-scope state is keyed by a private token object, not by `$this`, so
 * `clone $storage` can mint a new token and give the clone a copy of the current
 * scope's instance, as `FiberScopedRequestStack` does.
 *
 * Outside worker mode there is a single shared scope, so this behaves exactly
 * like a stock `UsageTrackingTokenStorage`.
 */
final class FiberScopedUsageTrackingTokenStorage implements TokenStorageInterface, ServiceSubscriberInterface
{
    private object $token;

    public function __construct(
        private readonly TokenStorageInterface $storage,
        private readonly ContainerInterface $container,
    ) {
        $this->token = new \stdClass();
    }

    /**
     * The clone gets its own token and a copy of the current scope's instance, so
     * changing the tracking flag on one never changes the other.
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

    public function setToken(?TokenInterface $token = null): void
    {
        $this->inner()->setToken($token);
    }

    public function enableUsageTracking(): void
    {
        $this->inner()->enableUsageTracking();
    }

    public function disableUsageTracking(): void
    {
        $this->inner()->disableUsageTracking();
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedServices(): array
    {
        return UsageTrackingTokenStorage::getSubscribedServices();
    }

    private function inner(): UsageTrackingTokenStorage
    {
        $inner = RequestScopes::current()->get(
            $this->token,
            fn (): UsageTrackingTokenStorage => new UsageTrackingTokenStorage($this->storage, $this->container),
        );
        \assert($inner instanceof UsageTrackingTokenStorage);

        return $inner;
    }
}
