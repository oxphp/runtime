<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Symfony;

use OxPHP\Runtime\Internal\Fiber\RequestScopes;
use OxPHP\Runtime\Internal\Symfony\FiberScopedLocaleSwitcher;
use OxPHP\Runtime\Internal\Symfony\FiberScopedLogoutUrlGenerator;
use OxPHP\Runtime\Internal\Symfony\FiberScopedRequestContext;
use OxPHP\Runtime\Internal\Symfony\FiberScopedRequestStack;
use OxPHP\Runtime\Internal\Symfony\FiberScopedTokenStorage;
use OxPHP\Runtime\Internal\Symfony\FiberScopedUsageTrackingTokenStorage;
use OxPHP\Runtime\Internal\Symfony\OverrideCheck;
use OxPHP\Runtime\Tests\Stub\OverrideCheck\ChildFixture;
use OxPHP\Runtime\Tests\Stub\OverrideCheck\ParentFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\UsageTrackingTokenStorage;
use Symfony\Component\Security\Http\Logout\LogoutUrlGenerator;
use Symfony\Component\Translation\LocaleSwitcher;

final class OverrideCheckTest extends TestCase
{
    protected function setUp(): void
    {
        RequestScopes::resetForTests();
    }

    protected function tearDown(): void
    {
        RequestScopes::resetForTests();
    }

    public function test_reports_public_methods_the_subclass_does_not_declare(): void
    {
        self::assertSame(['b'], OverrideCheck::missing(ChildFixture::class, ParentFixture::class));
        self::assertSame([], OverrideCheck::missing(ChildFixture::class, ParentFixture::class, ['b']));
    }

    public function test_a_class_that_does_not_extend_the_reference_is_missing_what_it_lacks(): void
    {
        $standalone = new class () {
            public function a(): void {}
        };

        self::assertSame(['b'], OverrideCheck::missing($standalone::class, ParentFixture::class));
    }

    public function test_a_constructor_that_takes_the_stock_parameters_differs_in_nothing(): void
    {
        $stock = new class ('en') {
            public function __construct(string $locale, ?\ArrayObject $context = null) {}
        };
        $same = new class ('en') {
            public function __construct(string $locale, ?\ArrayObject $context = null, int $extra = 0) {}
        };

        self::assertSame([], OverrideCheck::constructorDifferences($same::class, $stock::class));
        self::assertSame([], OverrideCheck::constructorDifferences(ChildFixture::class, ParentFixture::class), 'Neither declares one.');
    }

    public function test_reports_every_way_a_constructor_differs_from_the_stock_one(): void
    {
        // The definition's arguments were written for the stock constructor, by
        // position or by name, and setClass() keeps them.
        $stock = new class ('en', []) {
            /** @param list<mixed> $services */
            public function __construct(string $locale, array $services, ?\ArrayObject $context = null, int ...$flags) {}
        };
        $scoped = new class ('en', 1, new \ArrayObject(), 'x') {
            public function __construct(string $locale, int $services, \ArrayObject $requestContext, string $required) {}
        };
        $short = new class ('en') {
            public function __construct(string $locale) {}
        };

        self::assertSame([
            'takes int $services where the stock one takes array $services',
            'takes ArrayObject $requestContext where the stock one takes ?ArrayObject $context',
            'takes string $required where the stock one takes int ...$flags',
        ], OverrideCheck::constructorDifferences($scoped::class, $stock::class));
        self::assertSame(
            ['has no $services', 'has no $context', 'has no $flags'],
            OverrideCheck::constructorDifferences($short::class, $stock::class),
        );
    }

    public function test_reports_a_parameter_the_stock_constructor_may_omit_and_this_one_requires(): void
    {
        $stock = new class () {
            public function __construct(?\ArrayObject $context = null) {}
        };
        $required = new class (null, 'x') {
            public function __construct(?\ArrayObject $context, string $added) {}
        };

        self::assertSame(
            ['requires $context, which the stock one makes optional', 'requires $added, which the stock one has no parameter for'],
            OverrideCheck::constructorDifferences($required::class, $stock::class),
        );
    }

    public function test_reports_a_variadic_parameter_that_is_not_one_any_more(): void
    {
        $stock = new class () {
            public function __construct(int ...$flags) {}
        };
        $scoped = new class () {
            public function __construct(int $flags = 0) {}
        };

        self::assertSame(['takes int $flags where the stock one takes int ...$flags'], OverrideCheck::constructorDifferences($scoped::class, $stock::class));
    }

    public function test_shipped_subclasses_take_the_stock_constructor_parameters(): void
    {
        self::assertSame([], OverrideCheck::constructorDifferences(FiberScopedRequestStack::class, RequestStack::class));
        self::assertSame([], OverrideCheck::constructorDifferences(FiberScopedTokenStorage::class, TokenStorage::class));
        self::assertSame([], OverrideCheck::constructorDifferences(FiberScopedUsageTrackingTokenStorage::class, UsageTrackingTokenStorage::class));
        self::assertSame([], OverrideCheck::constructorDifferences(FiberScopedLogoutUrlGenerator::class, LogoutUrlGenerator::class));
        self::assertSame([], OverrideCheck::constructorDifferences(FiberScopedLocaleSwitcher::class, LocaleSwitcher::class));
    }

    public function test_shipped_subclasses_override_every_stateful_method(): void
    {
        self::assertSame([], OverrideCheck::missing(FiberScopedRequestStack::class, RequestStack::class, ['resetRequestFormats']));
        self::assertSame([], OverrideCheck::missing(FiberScopedRequestContext::class, RequestContext::class));
        self::assertSame([], OverrideCheck::missing(FiberScopedTokenStorage::class, TokenStorage::class));
        self::assertSame([], OverrideCheck::missing(FiberScopedLogoutUrlGenerator::class, LogoutUrlGenerator::class, ['registerListener']));
        self::assertSame([], OverrideCheck::missing(FiberScopedLocaleSwitcher::class, LocaleSwitcher::class));
        // `UsageTrackingTokenStorage` is final, so its scoped counterpart is a decorator, not a subclass.
        self::assertSame([], OverrideCheck::missing(FiberScopedUsageTrackingTokenStorage::class, UsageTrackingTokenStorage::class));
    }
}
