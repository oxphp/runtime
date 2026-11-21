<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Symfony;

use OxPHP\Runtime\Internal\Fiber\RequestScopes;
use OxPHP\Runtime\Internal\Symfony\FiberScopedLogoutUrlGenerator;
use OxPHP\Runtime\Tests\Stub\RequestFiber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\Logout\LogoutUrlGenerator;

final class FiberScopedLogoutUrlGeneratorTest extends TestCase
{
    protected function setUp(): void
    {
        RequestScopes::resetForTests();
    }

    protected function tearDown(): void
    {
        RequestScopes::resetForTests();
    }

    public function test_it_is_still_a_logout_url_generator(): void
    {
        self::assertInstanceOf(LogoutUrlGenerator::class, self::generator());
    }

    public function test_firewall_cleared_by_another_request_does_not_affect_this_one(): void
    {
        $generator = self::generator();

        $a = RequestFiber::start(static function () use ($generator): string {
            $generator->setCurrentFirewall('main');
            \Fiber::suspend();

            // Read through the service again, now that request B has come and gone.
            return $generator->getLogoutPath();
        });

        RequestFiber::start(static function () use ($generator): void {
            $generator->setCurrentFirewall('main');
            $generator->setCurrentFirewall(null);
        });

        $a->resume();

        self::assertSame('/logout', $a->result());
    }

    public function test_each_request_resolves_its_own_firewall_and_context(): void
    {
        $generator = self::generator();
        $generator->registerListener('admin', '/admin/logout', null, null, null, 'admin_context');

        $a = RequestFiber::start(static function () use ($generator): array {
            $generator->setCurrentFirewall('main');
            \Fiber::suspend();

            return [$generator->getLogoutPath(), $generator->getLogoutUrl()];
        });

        $b = RequestFiber::start(static function () use ($generator): array {
            $generator->setCurrentFirewall('other_name', 'admin_context');
            \Fiber::suspend();

            return [$generator->getLogoutPath(), $generator->getLogoutUrl()];
        });

        $a->resume();
        $b->resume();

        self::assertSame(['/logout', 'http://localhost/logout'], $a->result());
        self::assertSame(['/admin/logout', 'http://localhost/admin/logout'], $b->result());
    }

    public function test_without_a_current_firewall_it_still_throws_like_stock(): void
    {
        $generator = self::generator();

        $request = RequestFiber::start(static function () use ($generator): ?\Throwable {
            try {
                $generator->getLogoutPath();
            } catch (\Throwable $e) {
                return $e;
            }

            return null;
        });

        self::assertInstanceOf(\InvalidArgumentException::class, $request->result());
        self::assertStringContainsString('not behind a firewall', $request->result()->getMessage());
    }

    public function test_a_request_never_inherits_the_firewall_a_finished_request_left_applied(): void
    {
        $generator = self::generator();

        // A finished request leaves its firewall applied to the parent's private state
        // by its last getter call; the next request must not see it.
        $first = RequestFiber::start(static function () use ($generator): string {
            $generator->setCurrentFirewall('main');

            return $generator->getLogoutPath();
        });
        self::assertSame('/logout', $first->result());

        $second = RequestFiber::start(static function () use ($generator): ?\Throwable {
            try {
                $generator->getLogoutPath();
            } catch (\Throwable $e) {
                return $e;
            }

            return null;
        });

        self::assertInstanceOf(\InvalidArgumentException::class, $second->result());
    }

    public function test_reset_clears_only_the_callers_firewall(): void
    {
        $generator = self::generator();

        $a = RequestFiber::start(static function () use ($generator): string {
            $generator->setCurrentFirewall('main');
            \Fiber::suspend();

            return $generator->getLogoutPath();
        });

        $b = RequestFiber::start(static function () use ($generator): ?\Throwable {
            $generator->setCurrentFirewall('main');
            $generator->reset();

            try {
                $generator->getLogoutPath();
            } catch (\Throwable $e) {
                return $e;
            }

            return null;
        });

        $a->resume();

        self::assertInstanceOf(\InvalidArgumentException::class, $b->result());
        self::assertSame('/logout', $a->result());
    }

    public function test_outside_worker_mode_it_is_one_shared_firewall_even_inside_a_user_fiber(): void
    {
        $generator = self::generator();
        $generator->setCurrentFirewall('main');

        $fiber = new \Fiber(static fn (): string => $generator->getLogoutPath());
        $fiber->start();

        self::assertSame('/logout', $fiber->getReturn());
    }

    public function test_a_clone_keeps_the_firewall_and_is_independent_inside_a_request_scope(): void
    {
        $generator = self::generator();

        $request = RequestFiber::start(static function () use ($generator): array {
            $generator->setCurrentFirewall('main');

            $clone = clone $generator;
            $clonedPath = $clone->getLogoutPath();

            $clone->setCurrentFirewall(null);

            return [$clonedPath, $generator->getLogoutPath()];
        });

        self::assertSame(['/logout', '/logout'], $request->result());
    }

    private static function generator(): FiberScopedLogoutUrlGenerator
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/'));

        $generator = new FiberScopedLogoutUrlGenerator($requestStack);
        $generator->registerListener('main', '/logout', null, null);

        return $generator;
    }
}
