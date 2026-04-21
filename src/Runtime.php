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

    public function __construct(array $options = [])
    {
        parent::__construct($options + ['disable_dotenv' => true]);
        $this->bridge        = new OxPHP();
        $this->psr17         = new Psr17FactoryLocator($options['psr17_factory'] ?? null);
        $this->userResetters = \array_values($options['resetters'] ?? []);
    }

    public function getRunner(?object $application): RunnerInterface
    {
        // Fall back to stock Symfony runner when not running under the OxPHP SAPI
        // (e.g. dev environments using PHP-FPM or the built-in server).
        if (!\function_exists('oxphp_server_info')) {
            return parent::getRunner($application);
        }

        return match (true) {
            $application instanceof RequestHandlerInterface
                => new Psr15Runner($application, $this->bridge, $this->psr17, $this->userResetters),

            $application instanceof LaravelKernel
                => new LaravelRunner($application, $this->bridge, $this->userResetters),

            $application instanceof HttpKernelInterface
                => new HttpKernelRunner($application, $this->bridge, $this->userResetters),

            $application instanceof Response
                => new HttpFoundationResponseRunner($application, $this->bridge),

            $application instanceof ResponseInterface
                => new Psr7ResponseRunner($application, $this->bridge, $this->psr17),

            default => throw new \LogicException(\sprintf(
                'OxPHP Runtime does not support application of type "%s". '
                .'Supported: PSR-15 RequestHandler, Laravel Http Kernel, '
                .'Symfony HttpKernel, HttpFoundation Response, PSR-7 Response.',
                \get_debug_type($application),
            )),
        };
    }
}
