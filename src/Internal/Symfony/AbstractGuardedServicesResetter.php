<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Symfony;

use OxPHP\Runtime\Internal\Fiber\RequestScopes;
use Symfony\Contracts\Service\ResetInterface;

/**
 * @internal A `services_resetter` decorator that skips the reset while other requests are in flight.
 *
 * Symfony resets the `kernel.reset` services at almost every request start. With
 * overlapping requests on one worker, that would wipe state another request is
 * still using. This decorator calls the inner resetter only when `inFlight() <= 1`,
 * which means the request now booting is the only one on this worker. Otherwise it
 * returns without resetting. No pending flag is kept: the reset happens at the next
 * request that starts on an idle worker.
 *
 * Trade-off: under continuous overlap (for example an SSE stream that never closes),
 * shared `kernel.reset` services are not reset until the worker is idle. Per-scope
 * state needs no reset at all; it dies with its scope.
 *
 * The runner runs its resetters before it closes the request scope, so the calling
 * request is still counted and "only me" is `1`. Outside worker mode nothing opens a
 * scope, the count is `0`, and the inner resetter always runs, as in stock Symfony.
 *
 * Each call is recorded in the calling request's scope, skipped or not
 * (`reachedInCurrentRequest()`): once a request on the worker bailed out,
 * `SymfonyContainerResetter` resets at the end of a request only when Symfony
 * didn't reach this guard during it, which is when `Kernel::boot()` stopped
 * resetting.
 *
 * An exception from the inner resetter propagates unchanged to the caller:
 * `Kernel::boot()` lets it out of `handle()`, so the runner answers that request
 * with its fallback 500; `Container::reset()` swallows it; and
 * `SymfonyContainerResetter`, which calls this guard at the end of a request once
 * a request on the worker bailed out, runs inside `ResetterChain`, which logs it.
 *
 * symfony/dependency-injection 8.1 moved `ServicesResetterInterface` there from
 * symfony/http-kernel (7.3 to 8.0), so there is a subclass for each: on 8.1
 * HttpKernel's extends DependencyInjection's, and its subclass has both types.
 * `FiberIsolationPass` installs the one whose interface the decorated
 * `services_resetter` class implements, HttpKernel's when it implements both.
 */
abstract class AbstractGuardedServicesResetter implements ResetInterface
{
    /** Keys the calling request's "reached" flag in its scope */
    private static ?\stdClass $reached = null;

    public function __construct(private readonly ResetInterface $inner) {}

    public function reset(): void
    {
        RequestScopes::current()->set(self::reached(), true);

        if (RequestScopes::inFlight() > 1) {
            return;
        }

        $this->inner->reset();
    }

    /**
     * Whether a guard was called during the request now running, by Symfony or by
     * anyone else, whether it reset or skipped.
     */
    public static function reachedInCurrentRequest(): bool
    {
        return RequestScopes::current()->get(self::reached(), static fn (): bool => false) === true;
    }

    private static function reached(): \stdClass
    {
        return self::$reached ??= new \stdClass();
    }
}
