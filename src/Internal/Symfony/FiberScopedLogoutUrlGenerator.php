<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Symfony;

use OxPHP\Runtime\Internal\Fiber\RequestScopes;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Logout\LogoutUrlGenerator;

/**
 * @internal A `LogoutUrlGenerator` that keeps the current firewall per request scope.
 *
 * The stock generator holds the current firewall name and context in private
 * fields, set at `kernel.request` and cleared at `kernel.finish_request`. With
 * overlapping requests, one request finishing would clear the firewall another is
 * still rendering with. Here `setCurrentFirewall()` stores `[$key, $context]` in
 * the caller's scope and never touches the parent.
 *
 * The getters apply the scope's firewall to the parent with
 * `parent::setCurrentFirewall()` immediately before calling the parent getter.
 * That is safe because generating a URL never suspends the fiber: under
 * cooperative scheduling no other request runs between the two calls, so the
 * parent's private fields only ever hold the value this call just wrote.
 *
 * `registerListener()` is not overridden. Listeners are configuration, written
 * while the container builds the service, and stay shared.
 *
 * Per-scope state is keyed by a private token object, not by `$this`, so
 * `clone $generator` can mint a new token and give the clone the current scope's
 * firewall. Where the stock generator throws (no current firewall, unknown
 * firewall), this one throws the same exception, because the parent still decides.
 *
 * Outside worker mode there is a single shared scope, so this behaves exactly
 * like a stock `LogoutUrlGenerator`.
 */
final class FiberScopedLogoutUrlGenerator extends LogoutUrlGenerator
{
    private object $token;

    public function __construct(
        ?RequestStack $requestStack = null,
        ?UrlGeneratorInterface $router = null,
        ?TokenStorageInterface $tokenStorage = null,
    ) {
        $this->token = new \stdClass();

        parent::__construct($requestStack, $router, $tokenStorage);
    }

    /**
     * The clone gets its own token and the current scope's firewall, so changing
     * one never changes the other.
     */
    public function __clone()
    {
        $firewall = $this->firewall();

        $this->token = new \stdClass();

        RequestScopes::current()->set($this->token, $firewall);
    }

    public function setCurrentFirewall(?string $key, ?string $context = null): void
    {
        RequestScopes::current()->set($this->token, [$key, $context]);
    }

    public function getLogoutPath(?string $key = null): string
    {
        $this->applyScope();

        return parent::getLogoutPath($key);
    }

    public function getLogoutUrl(?string $key = null): string
    {
        $this->applyScope();

        return parent::getLogoutUrl($key);
    }

    public function reset(): void
    {
        RequestScopes::current()->set($this->token, [null, null]);
    }

    /**
     * Copies the caller's firewall into the parent's private fields, just before a getter reads them.
     */
    private function applyScope(): void
    {
        [$key, $context] = $this->firewall();

        parent::setCurrentFirewall($key, $context);
    }

    /**
     * @return array{?string, ?string}
     */
    private function firewall(): array
    {
        $firewall = RequestScopes::current()->get($this->token, static fn (): array => [null, null]);
        \assert(\is_array($firewall));

        /** @var array{?string, ?string} $firewall */
        return $firewall;
    }
}
