<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Symfony;

use OxPHP\Runtime\Internal\Fiber\RequestScopes;
use OxPHP\Runtime\Internal\Symfony\GuardedServicesResetter;
use OxPHP\Runtime\Tests\Stub\RequestFiber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface;

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

    public function test_it_is_a_services_resetter(): void
    {
        self::assertInstanceOf(ServicesResetterInterface::class, new GuardedServicesResetter(self::countingResetter()));
    }

    public function test_resets_outside_worker_mode(): void
    {
        $inner = self::countingResetter();

        new GuardedServicesResetter($inner)->reset();

        self::assertSame(1, $inner->count);
    }

    public function test_resets_when_the_caller_is_the_only_request(): void
    {
        $inner = self::countingResetter();
        $guarded = new GuardedServicesResetter($inner);

        $only = RequestFiber::start(static function () use ($guarded): void {
            $guarded->reset();
        });

        self::assertTrue($only->isTerminated());
        self::assertSame(1, $inner->count);
    }

    public function test_skips_while_another_request_is_in_flight(): void
    {
        $inner = self::countingResetter();
        $guarded = new GuardedServicesResetter($inner);

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
        $inner = new class ($failure) implements ServicesResetterInterface {
            public function __construct(private readonly \Throwable $failure) {}

            public function reset(): void
            {
                throw $this->failure;
            }
        };

        try {
            new GuardedServicesResetter($inner)->reset();
            self::fail('The inner exception must propagate.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
    }

    /**
     * @return ServicesResetterInterface&object{count: int}
     */
    private static function countingResetter(): ServicesResetterInterface
    {
        return new class () implements ServicesResetterInterface {
            public int $count = 0;

            public function reset(): void
            {
                ++$this->count;
            }
        };
    }
}
