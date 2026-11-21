<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Fiber;

/**
 * @internal One open request, as `RequestScopes::enterRequest()` handed it out.
 *
 * Opaque to callers: they only pass it back to `RequestScopes::leaveRequest()`.
 * It remembers the fiber and scope its request was opened with (none for a
 * degraded enter), so the request can be closed from anywhere, also from a
 * shutdown function, and it closes at most once.
 */
final class Ticket
{
    /** @var \WeakReference<\Fiber<mixed, mixed, mixed, mixed>>|null */
    private ?\WeakReference $fiber;

    private bool $open = true;

    /**
     * @param \Fiber<mixed, mixed, mixed, mixed>|null $fiber
     */
    public function __construct(?\Fiber $fiber, private ?RequestScope $scope)
    {
        $this->fiber = $fiber === null ? null : \WeakReference::create($fiber);
    }

    /**
     * Marks the ticket closed and lets go of its fiber and scope. Returns
     * `false` when it already was closed, so the caller undoes the request's
     * bookkeeping only once.
     */
    public function close(): bool
    {
        if (!$this->open) {
            return false;
        }

        $this->open = false;
        $this->fiber = null;
        $this->scope = null;

        return true;
    }

    /**
     * @return \Fiber<mixed, mixed, mixed, mixed>|null
     */
    public function fiber(): ?\Fiber
    {
        return $this->fiber?->get();
    }

    public function scope(): ?RequestScope
    {
        return $this->scope;
    }
}
