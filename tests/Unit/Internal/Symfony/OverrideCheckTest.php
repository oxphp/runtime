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
