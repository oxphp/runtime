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
 *  3. A fiber registered by `enterRequest()`: that request's scope.
 *  4. Any other fiber (one the app created inside a request): a private, empty
 *     scope created on first use and held in a `\WeakMap` keyed by that fiber.
 *     This fails closed: such a fiber never sees another request's state.
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
 */
final class RequestScopes
{
    private static ?RequestScope $shared = null;

    /** @var \WeakMap<\Fiber, RequestScope>|null Scopes of fibers registered by enterRequest() */
    private static ?\WeakMap $requestFibers = null;

    /** @var \WeakMap<\Fiber, RequestScope>|null Private scopes of fibers the app created */
    private static ?\WeakMap $userFibers = null;

    private static bool $inWorker = false;

    private static bool $degraded = false;

    private static int $inFlight = 0;

    /**
     * Opens a scope for the current fiber. Call it at the top of a worker iteration.
     */
    public static function enterRequest(): void
    {
        self::$inWorker = true;
        ++self::$inFlight;

        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            self::$degraded = true;

            return;
        }

        self::$requestFibers ??= new \WeakMap();
        self::$requestFibers[$fiber] = new RequestScope();
    }

    /**
     * Closes the current fiber's scope. Call it in the iteration's `finally`.
     */
    public static function leaveRequest(): void
    {
        --self::$inFlight;

        $fiber = \Fiber::getCurrent();

        if ($fiber !== null && self::$requestFibers !== null && self::$requestFibers->offsetExists($fiber)) {
            self::$requestFibers->offsetUnset($fiber);
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

        if (self::$requestFibers !== null && self::$requestFibers->offsetExists($fiber)) {
            return self::$requestFibers[$fiber];
        }

        self::$userFibers ??= new \WeakMap();

        if (!self::$userFibers->offsetExists($fiber)) {
            self::$userFibers[$fiber] = new RequestScope();
        }

        return self::$userFibers[$fiber];
    }

    /**
     * Number of open request scopes on this worker.
     */
    public static function inFlight(): int
    {
        return self::$inFlight;
    }

    /**
     * Whether `enterRequest()` ever ran outside a fiber, so isolation cannot be trusted.
     */
    public static function isDegraded(): bool
    {
        return self::$degraded;
    }

    public static function resetForTests(): void
    {
        self::$shared = null;
        self::$requestFibers = null;
        self::$userFibers = null;
        self::$inWorker = false;
        self::$degraded = false;
        self::$inFlight = 0;
    }

    private static function shared(): RequestScope
    {
        return self::$shared ??= new RequestScope();
    }
}
