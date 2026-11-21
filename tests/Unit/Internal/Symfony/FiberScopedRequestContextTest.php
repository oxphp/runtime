<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Symfony;

use OxPHP\Runtime\Internal\Fiber\RequestScopes;
use OxPHP\Runtime\Internal\Symfony\FiberScopedRequestContext;
use OxPHP\Runtime\Tests\Stub\RequestFiber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RequestContext;

final class FiberScopedRequestContextTest extends TestCase
{
    protected function setUp(): void
    {
        RequestScopes::resetForTests();
    }

    protected function tearDown(): void
    {
        RequestScopes::resetForTests();
    }

    public function test_each_request_starts_from_the_configured_template(): void
    {
        $context = new FiberScopedRequestContext(new RequestContext('/base', 'GET', 'example.com', 'https'));

        $request = RequestFiber::start(static fn (): array => [$context->getHost(), $context->getBaseUrl(), $context->getScheme()]);

        self::assertSame(['example.com', '/base', 'https'], $request->result());
    }

    public function test_building_the_scoped_context_leaves_the_template_values_in_the_shared_scope(): void
    {
        $template = new RequestContext('/base', 'GET', 'example.com', 'https');
        $context = new FiberScopedRequestContext($template);

        self::assertSame('example.com', $context->getHost());
        self::assertSame('/base', $context->getBaseUrl());
        self::assertSame('https', $context->getScheme());
        self::assertSame(443, $context->getHttpsPort());
    }

    public function test_a_request_never_writes_to_the_template(): void
    {
        $template = new RequestContext('/base', 'GET', 'example.com', 'https');
        $context = new FiberScopedRequestContext($template);

        RequestFiber::start(static function () use ($context): void {
            $context->setHost('changed.test');
            $context->setParameter('_locale', 'ru');
        });

        self::assertSame('example.com', $template->getHost());
        self::assertFalse($template->hasParameter('_locale'));
    }

    public function test_overlapping_requests_keep_their_own_context(): void
    {
        $context = new FiberScopedRequestContext(new RequestContext());

        $a = RequestFiber::start(static function () use ($context): array {
            $context->fromRequest(Request::create('http://a.test/x'));
            $context->setParameter('_locale', 'en');
            \Fiber::suspend();

            // Read through the service again, now that request B has entered.
            return [$context->getHost(), $context->getScheme(), $context->getParameter('_locale')];
        });

        $b = RequestFiber::start(static function () use ($context): array {
            $context->fromRequest(Request::create('https://b.test/y'));
            $context->setParameter('_locale', 'ru');
            \Fiber::suspend();

            return [$context->getHost(), $context->getScheme(), $context->getParameter('_locale')];
        });

        $a->resume();
        $b->resume();

        self::assertSame(['a.test', 'http', 'en'], $a->result());
        self::assertSame(['b.test', 'https', 'ru'], $b->result());
    }

    public function test_fluent_setters_return_the_scoped_context(): void
    {
        $context = new FiberScopedRequestContext(new RequestContext());

        self::assertSame($context, $context->setHost('h'));
        self::assertSame($context, $context->fromRequest(Request::create('http://c.test/z')));
        self::assertSame($context, $context->setParameter('k', 'v'));
        self::assertSame('c.test', $context->getHost());
    }
}
