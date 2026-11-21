<?php

declare(strict_types=1);

namespace OxPHP\Runtime;

use Illuminate\Contracts\Http\Kernel as LaravelKernel;
use OxPHP\Runtime\Internal\Bridge\OxPHP;
use OxPHP\Runtime\Internal\Emitter\Psr17FactoryLocator;
use OxPHP\Runtime\Internal\Runner\HttpFoundationResponseRunner;
use OxPHP\Runtime\Internal\Runner\HttpKernelRunner;
use OxPHP\Runtime\Internal\Runner\LaravelRunner;
use OxPHP\Runtime\Internal\Runner\Psr15Runner;
use OxPHP\Runtime\Internal\Runner\Psr7ResponseRunner;
use OxPHP\Runtime\Resetter\ResetterInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Runtime\RunnerInterface;
use Symfony\Component\Runtime\SymfonyRuntime;

final class Runtime extends SymfonyRuntime
{
    private readonly OxPHP $bridge;
    private readonly Psr17FactoryLocator $psr17;

    /** @var list<ResetterInterface|callable> */
    private readonly array $userResetters;

    /** @var array<array-key, mixed> $_SERVER as it stood before the boot wrote into it. */
    private readonly array $serverBeforeBoot;

    public function __construct(array $options = [])
    {
        // Taken before parent::__construct(), which is where dotenv boots.
        // autoload_runtime.php writes these two just before constructing us;
        // they are as much the boot's as what follows.
        $this->serverBeforeBoot = \array_diff_key($_SERVER, ['APP_RUNTIME' => 0, 'APP_RUNTIME_OPTIONS' => 0]);
        parent::__construct($options + ['disable_dotenv' => true]);
        $this->bridge = new OxPHP();
        $this->psr17 = new Psr17FactoryLocator($options['psr17_factory'] ?? null);
        $this->userResetters = \array_values($options['resetters'] ?? []);
    }

    public function getRunner(?object $application): RunnerInterface
    {
        // Fall back to stock Symfony runner when not running under the OxPHP SAPI
        // (e.g. CLI console commands, or dev environments using PHP-FPM or the
        // built-in server).
        if (\PHP_SAPI === 'cli' || !\function_exists('oxphp_server_info')) {
            return parent::getRunner($application);
        }

        return $this->resolveRunner($application);
    }

    /**
     * Map the application object returned by index.php to its OxPHP runner.
     * Separated from getRunner() so the dispatch table is unit-testable without
     * depending on the SAPI gate.
     */
    private function resolveRunner(?object $application): RunnerInterface
    {
        $bootServer = $this->bootServerVars();

        return match (true) {
            $application instanceof RequestHandlerInterface
                => new Psr15Runner($application, $this->bridge, $this->psr17, $this->userResetters, $bootServer),

            $application instanceof LaravelKernel
                => new LaravelRunner($application, $this->bridge, $this->userResetters, $bootServer),

            $application instanceof HttpKernelInterface
                => new HttpKernelRunner($application, $this->bridge, $this->userResetters, $bootServer),

            $application instanceof Response
                => new HttpFoundationResponseRunner($application, $this->bridge),

            $application instanceof ResponseInterface
                => new Psr7ResponseRunner($application, $this->bridge, $this->psr17),

            default => throw new \LogicException(\sprintf(
                'OxPHP Runtime does not support application of type "%s". '
                . 'Supported: PSR-15 RequestHandler, Laravel Http Kernel, '
                . 'Symfony HttpKernel, HttpFoundation Response, PSR-7 Response.',
                \get_debug_type($application),
            )),
        };
    }

    /**
     * The $_SERVER entries the worker's boot wrote — dotenv's variables,
     * APP_ENV, APP_DEBUG, APP_PROJECT_DIR, whatever the index.php callable set.
     * In worker mode the boot runs once, and OxPHP rebuilds $_SERVER for every
     * request, so the worker runner puts these back into each one.
     *
     * A diff rather than the whole array: the boot's own $_SERVER is a synthetic
     * request (REQUEST_URI "/", REMOTE_ADDR 127.0.0.1, ...) that must not stand
     * in for keys a real request lacks. HTTP_* always describes the request.
     *
     * @return array<array-key, mixed>
     */
    private function bootServerVars(): array
    {
        $vars = [];
        foreach ($_SERVER as $key => $value) {
            if (\is_string($key) && \str_starts_with($key, 'HTTP_')) {
                continue;
            }
            if (!\array_key_exists($key, $this->serverBeforeBoot) || $this->serverBeforeBoot[$key] !== $value) {
                $vars[$key] = $value;
            }
        }

        return $vars;
    }
}
