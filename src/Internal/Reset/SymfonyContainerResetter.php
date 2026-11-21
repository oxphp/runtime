<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Reset;

use OxPHP\Runtime\Internal\Fiber\RequestScopes;
use OxPHP\Runtime\Resetter\ResetterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Contracts\Service\ResetInterface;

/**
 * @internal Built-in resetter that tears down the Symfony container after a
 * worker request, which resets every service tagged `kernel.reset` and drops
 * all built instances.
 *
 * That teardown is the legacy behaviour and is only right when requests do not
 * overlap: with several requests in flight on one worker, it pulls services out
 * from under the ones still running. It therefore applies only when
 * `OxPHP\Runtime\Symfony\OxPHPBundle` has not installed per-request services,
 * meaning the `oxphp_runtime.fiber_isolation` container parameter is absent or
 * not `true`, or when the worker is degraded (a request ran outside any fiber,
 * so overlapping requests can no longer be told apart). With the marker `true`
 * on a healthy worker, `reset()` does nothing: the bundle's guarded
 * `services_resetter` does the resetting once the worker is idle.
 *
 * Without isolation, the first time a request ends while another is still in
 * flight, a hint goes to `error_log()` saying why and what to do. Each hint is
 * logged at most once per resetter instance (one per worker).
 *
 * The container is resolved lazily via Kernel::getContainer() — calling it
 * eagerly in the runner constructor would throw, since the kernel is not
 * booted until the first Kernel::handle() call. By the time reset() runs
 * (after handleRequest in the worker loop), boot has happened.
 */
final class SymfonyContainerResetter implements ResetterInterface
{
    private const MARKER = 'oxphp_runtime.fiber_isolation';

    private const REASON = 'oxphp_runtime.fiber_isolation_reason';

    private const HINT_NO_BUNDLE = "OxPHP runtime: requests overlapped on this worker and share Symfony's request state "
        . '(request stack, router context, locale, security token). Register OxPHP\Runtime\Symfony\OxPHPBundle in '
        . 'config/bundles.php to give each request its own; until then the container is rebuilt after every request.';

    private const HINT_NOT_ISOLATED = 'OxPHP runtime: requests overlapped on this worker, but '
        . 'OxPHP\Runtime\Symfony\OxPHPBundle could not isolate them: %s. '
        . 'The container is rebuilt after every request.';

    private const HINT_DEGRADED = 'OxPHP runtime: a request on this worker ran outside any fiber '
        . '(Fiber::getCurrent() returned null, which OxPHP before 0.15.0 does after a fatal error inside a Fiber), '
        . "so overlapping requests can't be told apart; the container is rebuilt after every request from now on. "
        . 'Upgrade OxPHP to 0.15 or later.';

    /** @var array<string, true> Hints already logged by this instance */
    private array $hinted = [];

    public function __construct(private readonly Kernel $kernel) {}

    public function reset(): void
    {
        $container = $this->kernel->getContainer();

        $hasMarker = $container->hasParameter(self::MARKER);

        if ($hasMarker && $container->getParameter(self::MARKER) === true) {
            if (!RequestScopes::isDegraded()) {
                return;
            }

            $this->hintOnce('degraded', self::HINT_DEGRADED);
        } elseif (RequestScopes::inFlight() > 1) {
            if ($hasMarker) {
                $this->hintOnce('not_isolated', \sprintf(self::HINT_NOT_ISOLATED, $this->reason($container)));
            } else {
                $this->hintOnce('no_bundle', self::HINT_NO_BUNDLE);
            }
        }

        if ($container instanceof ResetInterface) {
            $container->reset();
        }
    }

    private function reason(ContainerInterface $container): string
    {
        $reason = $container->hasParameter(self::REASON) ? $container->getParameter(self::REASON) : null;

        return \is_string($reason) && $reason !== '' ? $reason : 'no reason recorded';
    }

    private function hintOnce(string $key, string $message): void
    {
        if (isset($this->hinted[$key])) {
            return;
        }

        $this->hinted[$key] = true;
        \error_log($message);
    }
}
