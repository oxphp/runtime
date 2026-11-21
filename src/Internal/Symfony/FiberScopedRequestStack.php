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
 * Outside worker mode there is a single shared scope, so this behaves exactly
 * like a stock `RequestStack`.
 */
final class FiberScopedRequestStack extends RequestStack
{
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
        $inner = RequestScopes::current()->get($this, static fn (): RequestStack => new RequestStack());
        \assert($inner instanceof RequestStack);

        return $inner;
    }
}
