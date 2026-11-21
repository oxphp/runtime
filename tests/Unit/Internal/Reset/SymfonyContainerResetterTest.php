<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Reset;

use OxPHP\Runtime\Internal\Fiber\RequestScopes;
use OxPHP\Runtime\Internal\Reset\SymfonyContainerResetter;
use OxPHP\Runtime\Tests\Stub\RequestFiber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Contracts\Service\ResetInterface;

final class SymfonyContainerResetterTest extends TestCase
{
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

    private string|false $previousErrorLog = '';

    private string $logFile = '';

    protected function setUp(): void
    {
        RequestScopes::resetForTests();

        $this->previousErrorLog = \ini_get('error_log');
        $this->logFile = (string) \tempnam(\sys_get_temp_dir(), 'oxphp-resetter-');
        \ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        \ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
        if ($this->logFile !== '' && \is_file($this->logFile)) {
            \unlink($this->logFile);
        }

        RequestScopes::resetForTests();
    }

    public function test_calls_reset_when_container_implements_reset_interface(): void
    {
        $container = $this->createContainer();

        $kernel = $this->createKernelReturning($container);

        new SymfonyContainerResetter($kernel)->reset();
        self::assertSame(1, $container->calls);
    }

    public function test_is_noop_when_container_does_not_implement_reset_interface(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $kernel = $this->createKernelReturning($container);

        new SymfonyContainerResetter($kernel)->reset();
        self::assertTrue(true); // no error
    }

    public function test_marker_present_skips_the_teardown(): void
    {
        $container = $this->createContainer(['oxphp_runtime.fiber_isolation' => true]);

        new SymfonyContainerResetter($this->createKernelReturning($container))->reset();

        self::assertSame(0, $container->calls);
        self::assertSame('', $this->loggedOutput());
    }

    public function test_without_the_bundle_it_tears_down_as_before(): void
    {
        $container = $this->createContainer();

        new SymfonyContainerResetter($this->createKernelReturning($container))->reset();

        self::assertSame(1, $container->calls);
        self::assertSame('', $this->loggedOutput());
    }

    public function test_without_the_bundle_it_hints_once_when_requests_overlap(): void
    {
        $container = $this->createContainer();
        $resetter = new SymfonyContainerResetter($this->createKernelReturning($container));

        $other = RequestFiber::start(static function (): void {
            \Fiber::suspend();
        });
        $current = RequestFiber::start(static function () use ($resetter): void {
            $resetter->reset();
            $resetter->reset();
        });
        $other->resume();

        self::assertTrue($other->isTerminated());
        self::assertTrue($current->isTerminated());
        self::assertSame(2, $container->calls);
        self::assertSame(1, \substr_count($this->loggedOutput(), self::HINT_NO_BUNDLE));
    }

    public function test_bundle_without_isolation_hints_with_the_reason(): void
    {
        $container = $this->createContainer([
            'oxphp_runtime.fiber_isolation' => false,
            'oxphp_runtime.fiber_isolation_reason' => 'service "request_stack" uses X',
        ]);
        $resetter = new SymfonyContainerResetter($this->createKernelReturning($container));

        $other = RequestFiber::start(static function (): void {
            \Fiber::suspend();
        });
        $current = RequestFiber::start(static function () use ($resetter): void {
            $resetter->reset();
            $resetter->reset();
        });
        $other->resume();

        self::assertTrue($other->isTerminated());
        self::assertTrue($current->isTerminated());
        self::assertSame(2, $container->calls);
        self::assertSame(
            1,
            \substr_count($this->loggedOutput(), \sprintf(self::HINT_NOT_ISOLATED, 'service "request_stack" uses X')),
        );
        self::assertStringNotContainsString(self::HINT_NO_BUNDLE, $this->loggedOutput());
    }

    public function test_bundle_without_isolation_stays_quiet_without_overlap(): void
    {
        $container = $this->createContainer([
            'oxphp_runtime.fiber_isolation' => false,
            'oxphp_runtime.fiber_isolation_reason' => 'some reason',
        ]);

        $request = RequestFiber::start(function () use ($container): void {
            new SymfonyContainerResetter($this->createKernelReturning($container))->reset();
        });

        self::assertTrue($request->isTerminated());
        self::assertSame(1, $container->calls);
        self::assertSame('', $this->loggedOutput());
    }

    public function test_degraded_worker_tears_down_despite_the_marker_and_says_why_once(): void
    {
        $container = $this->createContainer(['oxphp_runtime.fiber_isolation' => true]);
        $resetter = new SymfonyContainerResetter($this->createKernelReturning($container));

        RequestScopes::enterRequest(); // from main: no current fiber, so the worker is degraded
        self::assertTrue(RequestScopes::isDegraded());

        $resetter->reset();
        $resetter->reset();

        self::assertSame(2, $container->calls);
        self::assertSame(1, \substr_count($this->loggedOutput(), self::HINT_DEGRADED));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function createContainer(array $parameters = []): ContainerInterface&ResetInterface
    {
        return new class ($parameters) implements ContainerInterface, ResetInterface {
            public int $calls = 0;

            /**
             * @param array<string, mixed> $parameters
             */
            public function __construct(private readonly array $parameters) {}

            public function reset(): void
            {
                $this->calls++;
            }
            public function set(string $id, ?object $service): void {}
            public function get(string $id, int $invalidBehavior = 1): ?object
            {
                return null;
            }
            public function has(string $id): bool
            {
                return false;
            }
            public function initialized(string $id): bool
            {
                return false;
            }
            public function getParameter(string $name): array|bool|string|int|float|\UnitEnum|null
            {
                if (!\array_key_exists($name, $this->parameters)) {
                    throw new ParameterNotFoundException($name);
                }

                return $this->parameters[$name];
            }
            public function hasParameter(string $name): bool
            {
                return \array_key_exists($name, $this->parameters);
            }
            public function setParameter(string $name, array|bool|string|int|float|\UnitEnum|null $value): void {}
        };
    }

    private function createKernelReturning(ContainerInterface $container): Kernel
    {
        $kernel = $this->createMock(Kernel::class);
        $kernel->method('getContainer')->willReturn($container);
        return $kernel;
    }

    private function loggedOutput(): string
    {
        return (string) \file_get_contents($this->logFile);
    }
}
