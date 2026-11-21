<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Symfony;

use OxPHP\Runtime\Internal\Fiber\RequestScopes;
use OxPHP\Runtime\Internal\Symfony\FiberScopedLocaleSwitcher;
use OxPHP\Runtime\Internal\Symfony\FiberScopedRequestContext;
use OxPHP\Runtime\Internal\Symfony\FiberScopedTranslator;
use OxPHP\Runtime\Tests\Stub\RequestFiber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\LocaleAwareInterface;

final class FiberScopedLocaleSwitcherTest extends TestCase
{
    private string $intlLocale = '';

    protected function setUp(): void
    {
        RequestScopes::resetForTests();
        $this->intlLocale = \Locale::getDefault();
    }

    protected function tearDown(): void
    {
        RequestScopes::resetForTests();
        \Locale::setDefault($this->intlLocale);
    }

    public function test_it_is_a_locale_switcher(): void
    {
        self::assertInstanceOf(LocaleSwitcher::class, self::switcher($this->translator(), self::context()));
    }

    public function test_overlapping_requests_keep_their_own_locale(): void
    {
        $translator = $this->translator();
        $context = self::context();
        $switcher = self::switcher($translator, $context);

        $a = RequestFiber::start(static function () use ($switcher, $translator, $context): array {
            $switcher->setLocale('en');
            \Fiber::suspend();

            // Read through the services again, now that request B has entered.
            return [$switcher->getLocale(), $translator->getLocale(), $context->getParameter('_locale')];
        });

        $b = RequestFiber::start(static function () use ($switcher, $translator, $context): array {
            $switcher->setLocale('ru');

            return [$switcher->getLocale(), $translator->getLocale(), $context->getParameter('_locale'), $translator->trans('hello')];
        });

        self::assertSame(['ru', 'ru', 'ru', 'Привет'], $b->result());

        $a->resume();

        self::assertSame(['en', 'en', 'en'], $a->result());
    }

    public function test_fresh_request_starts_at_the_boot_locale(): void
    {
        $switcher = self::switcher($this->translator(), self::context());

        RequestFiber::start(static function () use ($switcher): void {
            $switcher->setLocale('ru');
        });

        self::assertSame('en', RequestFiber::start(static fn (): string => $switcher->getLocale())->result());
    }

    public function test_the_fan_out_still_reaches_the_process_default_and_every_locale_aware_service(): void
    {
        $translator = $this->translator();
        $context = self::context();
        $custom = new class () implements LocaleAwareInterface {
            public string $locale = 'en';

            public function setLocale(string $locale): void
            {
                $this->locale = $locale;
            }

            public function getLocale(): string
            {
                return $this->locale;
            }
        };
        $switcher = new FiberScopedLocaleSwitcher('en', [$translator, $custom], $context);

        RequestFiber::start(static function () use ($switcher): void {
            $switcher->setLocale('ru');
        });

        self::assertSame('ru', \Locale::getDefault());
        self::assertSame('ru', $custom->locale);
    }

    public function test_run_with_locale_restores_the_callers_locale(): void
    {
        $translator = $this->translator();
        $context = self::context();
        $switcher = self::switcher($translator, $context);

        $request = RequestFiber::start(static function () use ($switcher, $translator, $context): array {
            $switcher->setLocale('ru');

            $inside = $switcher->runWithLocale('en', static fn (string $locale): array => [$locale, $switcher->getLocale(), $translator->trans('hello')]);

            return [$inside, $switcher->getLocale(), $translator->getLocale(), $context->getParameter('_locale')];
        });

        self::assertSame([['en', 'en', 'Hello'], 'ru', 'ru', 'ru'], $request->result());
    }

    public function test_run_with_locale_does_not_disturb_an_overlapping_request(): void
    {
        $translator = $this->translator();
        $switcher = self::switcher($translator, self::context());

        $a = RequestFiber::start(static function () use ($switcher, $translator): array {
            $switcher->setLocale('en');

            $inside = $switcher->runWithLocale('ru', static function () use ($switcher, $translator): array {
                \Fiber::suspend();

                return [$switcher->getLocale(), $translator->getLocale()];
            });

            return [$inside, $switcher->getLocale(), $translator->getLocale()];
        });

        $b = RequestFiber::start(static function () use ($switcher, $translator): array {
            $switcher->setLocale('en');
            $switcher->runWithLocale('ru', static fn (): null => null);

            return [$switcher->getLocale(), $translator->getLocale()];
        });

        self::assertSame(['en', 'en'], $b->result());

        $a->resume();

        self::assertSame([['ru', 'ru'], 'en', 'en'], $a->result());
    }

    public function test_run_with_locale_restores_the_locale_when_the_callback_throws(): void
    {
        $switcher = self::switcher($this->translator(), self::context());

        $request = RequestFiber::start(static function () use ($switcher): string {
            $switcher->setLocale('ru');

            try {
                $switcher->runWithLocale('en', static function (): never {
                    throw new \RuntimeException('boom');
                });
            } catch (\RuntimeException) {
            }

            return $switcher->getLocale();
        });

        self::assertSame('ru', $request->result());
    }

    public function test_reset_returns_the_caller_to_the_boot_locale_only(): void
    {
        $translator = $this->translator();
        $context = self::context();
        $switcher = self::switcher($translator, $context);

        $a = RequestFiber::start(static function () use ($switcher, $translator, $context): array {
            $switcher->setLocale('ru');
            \Fiber::suspend();

            return [$switcher->getLocale(), $translator->getLocale(), $context->getParameter('_locale')];
        });

        $b = RequestFiber::start(static function () use ($switcher, $translator, $context): array {
            $switcher->setLocale('ru');
            $switcher->reset();

            return [$switcher->getLocale(), $translator->getLocale(), $context->getParameter('_locale')];
        });

        self::assertSame(['en', 'en', 'en'], $b->result());

        $a->resume();

        self::assertSame(['ru', 'ru', 'ru'], $a->result());
    }

    public function test_a_clone_starts_from_the_current_locale_and_is_independent(): void
    {
        $switcher = self::switcher($this->translator(), self::context());

        $request = RequestFiber::start(static function () use ($switcher): array {
            $switcher->setLocale('ru');

            $clone = clone $switcher;
            $afterClone = $clone->getLocale();

            $clone->setLocale('en');

            return [$afterClone, $clone->getLocale(), $switcher->getLocale()];
        });

        self::assertSame(['ru', 'en', 'ru'], $request->result());
    }

    public function test_outside_worker_mode_it_behaves_like_the_stock_switcher(): void
    {
        $translator = $this->translator();
        $context = self::context();
        $switcher = self::switcher($translator, $context);

        self::assertSame('en', $switcher->getLocale());

        $switcher->setLocale('ru');
        self::assertSame('ru', $switcher->getLocale());
        self::assertSame('ru', $translator->getLocale());
        self::assertSame('ru', $context->getParameter('_locale'));

        // A user-created fiber sees the same locale, in both directions.
        $fiber = new \Fiber(static function () use ($switcher): string {
            $seen = $switcher->getLocale();
            $switcher->setLocale('en');

            return $seen;
        });
        $fiber->start();

        self::assertSame('ru', $fiber->getReturn());
        self::assertSame('en', $switcher->getLocale());

        $switcher->setLocale('ru');
        $switcher->reset();
        self::assertSame('en', $switcher->getLocale());
    }

    private function translator(): FiberScopedTranslator
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['hello' => 'Hello'], 'en');
        $translator->addResource('array', ['hello' => 'Привет'], 'ru');

        return new FiberScopedTranslator($translator);
    }

    private static function context(): FiberScopedRequestContext
    {
        return new FiberScopedRequestContext(new RequestContext());
    }

    private static function switcher(FiberScopedTranslator $translator, FiberScopedRequestContext $context): FiberScopedLocaleSwitcher
    {
        return new FiberScopedLocaleSwitcher('en', [$translator], $context);
    }
}
