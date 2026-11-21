<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Symfony;

use OxPHP\Runtime\Internal\Fiber\RequestScopes;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * @internal A `RequestStack` that keeps one stock stack per request scope.
 *
 * Every public instance method delegates to the stack held by the caller's
 * scope, so a request never sees another request's pushed requests, and the
 * semantics match stock Symfony on every version. `resetRequestFormats()` is
 * not overridden: it touches static `Request` state, not this stack.
 *
 * Per-scope state is keyed by a private token object, not by `$this`, so
 * `clone $stack` can mint a new token and give the clone a copy of the current
 * scope's stack. Symfony clones the stack (`AbstractSessionListener` pops the
 * clone until empty), and a clone must keep the requests without draining the
 * original.
 *
 * Outside worker mode there is a single shared scope, so this behaves exactly
 * like a stock `RequestStack`.
 */
final class FiberScopedRequestStack extends RequestStack
{
    private object $token;

    /**
     * @param Request[] $requests
     */
    public function __construct(array $requests = [])
    {
        // The token must exist before the parent constructor pushes `$requests`.
        $this->token = new \stdClass();

        parent::__construct($requests);
    }

    /**
     * The clone gets its own token and a copy of the current scope's stack, so
     * popping or pushing on one never changes the other.
     */
    public function __clone()
    {
        $inner = $this->inner();

        $this->token = new \stdClass();

        RequestScopes::current()->set($this->token, clone $inner);
    }

    public function push(Request $request): void
    {
        $this->inner()->push($request);
    }

    public function pop(): ?Request
    {
        return $this->inner()->pop();
    }

    public function getCurrentRequest(): ?Request
    {
        return $this->inner()->getCurrentRequest();
    }

    public function getMainRequest(): ?Request
    {
        return $this->inner()->getMainRequest();
    }

    public function getParentRequest(): ?Request
    {
        return $this->inner()->getParentRequest();
    }

    public function getSession(): SessionInterface
    {
        return $this->inner()->getSession();
    }

    private function inner(): RequestStack
    {
        $inner = RequestScopes::current()->get($this->token, static fn (): RequestStack => new RequestStack());
        \assert($inner instanceof RequestStack);

        return $inner;
    }
}
