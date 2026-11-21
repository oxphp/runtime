<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Yii;

use OxPHP\Runtime\Internal\Yii\ContainerBlueprint;
use OxPHP\Runtime\Internal\Yii\PerRequestServiceRequested;
use OxPHP\Runtime\Internal\Yii\SharedServices;
use OxPHP\Runtime\Tests\Stub\Yii\ArrayConfig;
use OxPHP\Runtime\Tests\Stub\Yii\Guard\HoldsClosure;
use OxPHP\Runtime\Tests\Stub\Yii\Guard\HoldsContainer;
use OxPHP\Runtime\Tests\Stub\Yii\Guard\HoldsInjector;
use OxPHP\Runtime\Tests\Stub\Yii\Guard\LazyService;
use OxPHP\Runtime\Tests\Stub\Yii\Guard\NeedsCurrentRoute;
use OxPHP\Runtime\Tests\Stub\Yii\Guard\NeedsTaggedProbes;
use OxPHP\Runtime\Tests\Stub\Yii\PerRequestProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Yiisoft\Definitions\Reference;
use Yiisoft\Di\BuildingException;
use Yiisoft\Di\Container;
use Yiisoft\Injector\Injector;
use Yiisoft\Router\CurrentRoute;

final class SharedServicesTest extends TestCase
{
    private const DEPENDS_ON_PER_REQUEST = 'Shared service "%1$s" depends on per-request service "%2$s". Remove "%1$s" from sharedServices, or stop it depending on "%2$s".';
    private const LISTED_PER_REQUEST = 'Service "%s" cannot be shared: its definition has a "reset" callback, so it holds per-request state. Remove it from sharedServices.';
    private const HOLDS_CONTAINER = 'Shared service "%1$s" holds %2$s at %3$s. A shared service must not resolve services after boot; remove "%1$s" from sharedServices.';

    public function test_a_shared_service_that_needs_a_per_request_service_fails_naming_both(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(\sprintf(self::DEPENDS_ON_PER_REQUEST, NeedsCurrentRoute::class, CurrentRoute::class));

        SharedServices::resolve($this->blueprint(), [NeedsCurrentRoute::class], false);
    }

    public function test_the_shared_container_hits_the_stand_in_instead_of_building_a_per_request_service(): void
    {
        try {
            SharedServices::resolve($this->blueprint(), [NeedsCurrentRoute::class], false);
            self::fail('The shared service was not rejected.');
        } catch (\LogicException $e) {
            $standIns = [];
            for ($cause = $e->getPrevious(); $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof PerRequestServiceRequested) {
                    $standIns[] = $cause->id;
                }
            }

            self::assertSame([CurrentRoute::class], $standIns);
        }
    }

    public function test_a_shared_service_that_needs_a_tag_with_a_per_request_service_fails_naming_both(): void
    {
        $bp = $this->blueprint([
            'probe.stateful' => [
                'class' => PerRequestProbe::class,
                'tags' => ['probes'],
                'reset' => function (): void {
                    $this->bootstrapped = false;
                },
            ],
            NeedsTaggedProbes::class => [
                'class' => NeedsTaggedProbes::class,
                '__construct()' => [Reference::to('tag@probes')],
            ],
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(\sprintf(self::DEPENDS_ON_PER_REQUEST, NeedsTaggedProbes::class, 'probe.stateful'));

        SharedServices::resolve($bp, [NeedsTaggedProbes::class], false);
    }

    public function test_listing_a_per_request_service_fails(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(\sprintf(self::LISTED_PER_REQUEST, CurrentRoute::class));

        SharedServices::resolve($this->blueprint(), [CurrentRoute::class], false);
    }

    public function test_a_shared_service_holding_the_container_fails_with_the_property_path(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(\sprintf(self::HOLDS_CONTAINER, HoldsContainer::class, Container::class, 'property path ->container'));

        SharedServices::resolve($this->blueprint(), [HoldsContainer::class], false);
    }

    public function test_a_shared_service_holding_an_injector_fails(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(\sprintf(self::HOLDS_CONTAINER, HoldsInjector::class, Injector::class, 'property path ->injector'));

        SharedServices::resolve($this->blueprint(), [HoldsInjector::class], false);
    }

    public function test_a_shared_service_holding_a_closure_over_the_container_fails(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(\sprintf(self::HOLDS_CONTAINER, HoldsClosure::class, Container::class, 'property path ->resolve{use $c}'));

        SharedServices::resolve($this->blueprint(), [HoldsClosure::class], false);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function provideA_shared_lazy_service_whose_initializer_keeps_the_container_fails_and_stays_uninitializedCases(): iterable
    {
        yield 'ghost' => [true];
        yield 'proxy' => [false];
    }

    #[DataProvider('provideA_shared_lazy_service_whose_initializer_keeps_the_container_fails_and_stays_uninitializedCases')]
    public function test_a_shared_lazy_service_whose_initializer_keeps_the_container_fails_and_stays_uninitialized(bool $ghost): void
    {
        $made = null;
        $bp = $this->blueprint([
            LazyService::class => static function (ContainerInterface $c) use ($ghost, &$made): LazyService {
                $class = new \ReflectionClass(LazyService::class);

                return $made = $ghost
                    ? $class->newLazyGhost(static function (LazyService $s) use ($c): void {
                        $s->value = $c;
                    })
                    : $class->newLazyProxy(static function () use ($c): LazyService {
                        $s = new LazyService();
                        $s->value = $c;

                        return $s;
                    });
            },
        ]);

        try {
            SharedServices::resolve($bp, [LazyService::class], false);
            self::fail('The lazy service was shared.');
        } catch (\LogicException $e) {
            self::assertSame(\sprintf(self::HOLDS_CONTAINER, LazyService::class, Container::class, 'property path {initializer}{use $c}'), $e->getMessage());
        }

        self::assertInstanceOf(LazyService::class, $made);
        self::assertTrue(new \ReflectionClass(LazyService::class)->isUninitializedLazyObject($made));
    }

    public function test_sharing_the_container_itself_fails(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(\sprintf(self::HOLDS_CONTAINER, ContainerInterface::class, Container::class, 'the service itself'));

        SharedServices::resolve($this->blueprint(), [ContainerInterface::class], false);
    }

    public function test_any_other_build_failure_is_rethrown_as_is(): void
    {
        $bp = $this->blueprint(['probe.broken' => static fn (): never => throw new \RuntimeException('Probe is broken.')]);

        $this->expectException(BuildingException::class);
        $this->expectExceptionMessage('Probe is broken.');

        SharedServices::resolve($bp, ['probe.broken'], false);
    }

    /**
     * @param array<string, mixed> $di
     */
    private function blueprint(array $di = []): ContainerBlueprint
    {
        return ContainerBlueprint::fromConfig(new ArrayConfig([
            'di' => [
                CurrentRoute::class => [
                    'class' => CurrentRoute::class,
                    'reset' => function (): void {
                        $this->route = null;
                        $this->uri = null;
                        $this->arguments = [];
                    },
                ],
                ...$di,
            ],
            'providers' => [],
            'delegates' => [],
            'tags' => [],
        ]), 'di', 'providers', 'delegates', 'tags');
    }
}
