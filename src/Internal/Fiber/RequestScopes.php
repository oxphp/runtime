<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Fiber;

/**
 * @internal Static registry of per-request scopes, keyed by the running fiber.
 *
 * It is static so the runner (outside the container) can open and close scopes,
 * and so a container rebuild cannot lose in-flight state.
 *
 * `current()` resolves the caller's scope by these rules, in order:
 *
 *  1. `enterRequest()` was never called (FPM, CLI, tests, traditional mode):
 *     one shared scope for everything, regardless of fibers. This is stock
 *     behaviour.
 *  2. Worker mode and no running fiber (worker boot code, destructors that run
 *     between requests): the shared scope.
 *  3. A fiber a request was entered on: that request's scope, also after the
 *     request closed, until the fiber's next request replaces it. Whatever runs
 *     on the fiber in between is that request's own: its shutdown functions
 *     (OxPHP runs them on the request's fiber, also after a fatal error) and the
 *     destructors of what it leaves.
 *  4. Any other fiber that a request fiber is running, directly or through other
 *     fibers (one the app created, a scheduler's, the one PHP's garbage collector
 *     runs destructors in): a private scope of that fiber under that request,
 *     empty at first. Such a fiber can outlive the request and be resumed by
 *     another; it then gets another private scope under that one. This fails
 *     closed: it never sees a request's own state, nor what it kept while it ran
 *     for another request.
 *  5. Any other fiber with no request fiber running it: the shared scope, as in
 *     rule 2, since no request is running.
 *
 * Rule 4 finds the request by `\Fiber::isRunning()`, which is also true of a
 * fiber that resumed the caller and has not been suspended since. OxPHP runs one
 * request fiber at a time on a worker and never resumes one from another, so at
 * most one request fiber is running. It scans the fibers requests were entered
 * on, so it costs one call per such fiber, and only for rule 4's callers.
 *
 * `enterRequest()` always maps the current fiber to a new scope and replaces
 * any existing mapping, because OxPHP reuses `\Fiber` objects across requests:
 * a recycled fiber must never inherit the previous request's state.
 *
 * If `enterRequest()` runs with no current fiber, the worker no longer isolates
 * requests (OxPHP 0.11 to 0.14 could leave `\Fiber::getCurrent()` returning
 * `null` after a fatal error inside a userland fiber). The registry then marks
 * itself degraded for the rest of the worker's life so callers can fall back to
 * stock behaviour.
 *
 * A request counts as in flight from `enterRequest()` until its first
 * `leaveRequest()`, at most once. The runner closes it twice over: in the
 * iteration's `finally`, and in a shutdown function it registered before the
 * enter. OxPHP skips every `finally` of a request that ends in a fatal error, a
 * time limit, `exit()`, a cancel or a client gone mid-stream, but still runs the
 * request's shutdown functions. A ticket still open when its shutdown function
 * runs is such a request, and the registry marks itself bailed out for the rest
 * of the worker's life (`hasBailedOut()`), since whatever the request was in the
 * middle of was never unwound either.
 */
final class RequestScopes
{
    private static ?RequestScope $shared = null;

    /** @var \WeakMap<\Fiber, RequestScope>|null Scope of the latest request entered on each fiber */
    private static ?\WeakMap $requestFibers = null;

    /**
     * The requests in flight. Weak, so a ticket nobody can close any more is not
     * counted either.
     *
     * @var \WeakMap<Ticket, true>|null
     */
    private static ?\WeakMap $open = null;

    private static bool $inWorker = false;

    private static bool $degraded = false;

    private static bool $bailedOut = false;

    /**
     * Opens `$ticket`'s request, with a new scope for the current fiber. Call it
     * at the top of a worker iteration, after registering the request's shutdown
     * close with the same ticket. A ticket is entered once.
     */
    public static function enterRequest(Ticket $ticket): void
    {
        $fiber = \Fiber::getCurrent();
        $scope = $fiber === null ? null : new RequestScope();
        self::$open ??= new \WeakMap();
        self::$requestFibers ??= new \WeakMap();

        // No calls from here on: a time limit or a cancel lands at a call, and the
        // request must be counted exactly when its ticket is open.
        self::$inWorker = true;

        if ($fiber === null) {
            self::$degraded = true;
        } else {
            self::$requestFibers[$fiber] = $scope;
        }

        self::$open[$ticket] = true;
    }

    /**
     * Closes the ticket's request. Only the first call for an entered ticket
     * counts, so the iteration's `finally` and its shutdown function can both
     * call it, and one for a ticket never entered does nothing.
     *
     * The fiber keeps the request's scope (see rule 3).
     *
     * Pass `$atShutdown` from the request's shutdown function: a ticket still
     * open then belongs to a request that skipped its `finally`, which marks the
     * worker bailed out.
     */
    public static function leaveRequest(Ticket $ticket, bool $atShutdown = false): void
    {
        if (self::$open === null || !self::$open->offsetExists($ticket)) {
            return;
        }

        self::$open->offsetUnset($ticket);

        if ($atShutdown) {
            self::$bailedOut = true;
        }
    }

    public static function current(): RequestScope
    {
        if (!self::$inWorker) {
            return self::shared();
        }

        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            return self::shared();
        }

        $requests = self::$requestFibers;

        if ($requests === null) {
            return self::shared();
        }

        if ($requests->offsetExists($fiber)) {
            return $requests[$fiber];
        }

        foreach ($requests as $requestFiber => $scope) {
            if ($requestFiber->isRunning()) {
                return $scope->fiberScope($fiber);
            }
        }

        return self::shared();
    }

    /**
     * Number of open requests on this worker: tickets entered and not closed
     * yet, degraded ones included.
     */
    public static function inFlight(): int
    {
        return self::$open === null ? 0 : \count(self::$open);
    }

    /**
     * Whether `enterRequest()` ever ran outside a fiber, so isolation cannot be trusted.
     */
    public static function isDegraded(): bool
    {
        return self::$degraded;
    }

    /**
     * Whether a request on this worker ever ended without its `finally` (its
     * ticket was still open when its shutdown function ran).
     */
    public static function hasBailedOut(): bool
    {
        return self::$bailedOut;
    }

    public static function resetForTests(): void
    {
        self::$shared = null;
        self::$requestFibers = null;
        self::$open = null;
        self::$inWorker = false;
        self::$degraded = false;
        self::$bailedOut = false;
    }

    private static function shared(): RequestScope
    {
        return self::$shared ??= new RequestScope();
    }
}
