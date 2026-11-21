<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Symfony;

use OxPHP\Runtime\Internal\Fiber\RequestScopes;
use OxPHP\Runtime\Internal\Symfony\AbstractGuardedServicesResetter;
use OxPHP\Runtime\Internal\Symfony\GuardedServicesResetter;
use OxPHP\Runtime\Internal\Symfony\LegacyGuardedServicesResetter;
use OxPHP\Runtime\Tests\Stub\InstalledServicesResetter;
use OxPHP\Runtime\Tests\Stub\RequestFiber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServicesResetter;
use Symfony\Component\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetter as LegacyServicesResetter;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface as LegacyServicesResetterInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The guard logic runs against the guard the installed Symfony gets. HttpKernel's
 * `ServicesResetterInterface` is only autoloaded when DependencyInjection has none
 * (before 8.1): from 8.1 loading it triggers a deprecation.
 */
final class GuardedServicesResetterTest extends TestCase
{
    protected function setUp(): void
    {
        RequestScopes::resetForTests();
    }

    protected function tearDown(): void
    {
        RequestScopes::resetForTests();
    }

    public function test_the_guard_decorates_the_dependency_injection_services_resetter(): void
    {
        if (!InstalledServicesResetter::inDependencyInjection()) {
            self::markTestSkipped('symfony/dependency-injection < 8.1 has no ServicesResetterInterface; LegacyGuardedServicesResetter is used.');
        }

        $guarded = new GuardedServicesResetter(new ServicesResetter(new \ArrayIterator([]), []));

        self::assertInstanceOf(ServicesResetterInterface::class, $guarded);
    }

    public function test_the_legacy_guard_decorates_the_http_kernel_services_resetter(): void
    {
        if (InstalledServicesResetter::inDependencyInjection()) {
            self::markTestSkipped('symfony/dependency-injection 8.1 or later uses GuardedServicesResetter; HttpKernel\'s deprecated ServicesResetterInterface is not loaded.');
        }

        if (!\interface_exists(LegacyServicesResetterInterface::class)) {
            self::markTestSkipped('symfony/http-kernel < 7.3 has no ServicesResetterInterface.');
        }

        $guarded = new LegacyGuardedServicesResetter(new LegacyServicesResetter(new \ArrayIterator([]), []));

        self::assertInstanceOf(LegacyServicesResetterInterface::class, $guarded);
    }

    public function test_resets_outside_worker_mode(): void
    {
        $inner = self::countingResetter();

        self::guarded($inner)->reset();

        self::assertSame(1, $inner->count);
    }

    public function test_resets_when_the_caller_is_the_only_request(): void
    {
        $inner = self::countingResetter();
        $guarded = self::guarded($inner);

        $only = RequestFiber::start(static function () use ($guarded): void {
            $guarded->reset();
        });

        self::assertTrue($only->isTerminated());
        self::assertSame(1, $inner->count);
    }

    public function test_skips_while_another_request_is_in_flight(): void
    {
        $inner = self::countingResetter();
        $guarded = self::guarded($inner);

        $a = RequestFiber::start(static function (): void {
            \Fiber::suspend();
        });

        $b = RequestFiber::start(static function () use ($guarded): void {
            $guarded->reset();
        });

        self::assertTrue($b->isTerminated());
        self::assertFalse($a->isTerminated());
        self::assertSame(0, $inner->count, 'The reset must wait while request A is still in flight.');

        $a->resume();
        self::assertTrue($a->isTerminated());

        $c = RequestFiber::start(static function () use ($guarded): void {
            $guarded->reset();
        });

        self::assertTrue($c->isTerminated());
        self::assertSame(1, $inner->count, 'The reset must run once the worker is idle again.');
    }

    public function test_an_inner_failure_propagates_unchanged(): void
    {
        $failure = new \RuntimeException('inner reset failed');
        $inner = new class ($failure) implements ResetInterface {
            public function __construct(private readonly \Throwable $failure) {}

            public function reset(): void
            {
                throw $this->failure;
            }
        };

        try {
            self::guarded($inner)->reset();
            self::fail('The inner exception must propagate.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
    }

    /**
     * The guard `FiberIsolationPass` installs on the installed Symfony, around `$inner`.
     */
    private static function guarded(ResetInterface $inner): AbstractGuardedServicesResetter
    {
        $class = InstalledServicesResetter::guard();

        return new $class($inner);
    }

    /**
     * @return ResetInterface&object{count: int}
     */
    private static function countingResetter(): ResetInterface
    {
        return new class () implements ResetInterface {
            public int $count = 0;

            public function reset(): void
            {
                ++$this->count;
            }
        };
    }
}
