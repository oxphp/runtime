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
 * An exception from the inner resetter propagates unchanged to the caller:
 * `Kernel::boot()` lets it out of `handle()`, so the runner answers that request
 * with its fallback 500; `Container::reset()` swallows it; and
 * `SymfonyContainerResetter`, which calls this guard at the end of a request once
 * a request on the worker bailed out, runs inside `ResetterChain`, which logs it.
 *
 * symfony/dependency-injection 8.1 moved `ServicesResetterInterface` there from
 * symfony/http-kernel (7.3 to 8.0), so each subclass implements one of the two.
 * `FiberIsolationPass` installs the one whose interface the decorated
 * `services_resetter` class implements, HttpKernel's when it implements both.
 */
abstract class AbstractGuardedServicesResetter implements ResetInterface
{
    public function __construct(private readonly ResetInterface $inner) {}

    public function reset(): void
    {
        if (RequestScopes::inFlight() > 1) {
            return;
        }

        $this->inner->reset();
    }
}
