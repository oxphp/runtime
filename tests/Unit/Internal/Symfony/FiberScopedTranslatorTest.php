<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Symfony;

use OxPHP\Runtime\Internal\Fiber\RequestScopes;
use OxPHP\Runtime\Internal\Symfony\FiberScopedTranslator;
use OxPHP\Runtime\Tests\Stub\RequestFiber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\Translation\DataCollectorTranslator;
use Symfony\Component\Translation\Exception\InvalidArgumentException;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class FiberScopedTranslatorTest extends TestCase
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

    public function test_overlapping_requests_translate_in_their_own_locale(): void
    {
        $translator = new FiberScopedTranslator(self::inner());

        $a = RequestFiber::start(static function () use ($translator): array {
            $translator->setLocale('en');
            \Fiber::suspend();

            // Read through the service again, now that request B has entered.
            return [$translator->trans('hello'), $translator->getLocale()];
        });

        $b = RequestFiber::start(static function () use ($translator): string {
            $translator->setLocale('ru');

            return $translator->trans('hello');
        });

        self::assertSame('Привет', $b->result());

        $a->resume();

        self::assertSame(['Hello', 'en'], $a->result());
    }

    public function test_explicit_locale_argument_wins(): void
    {
        $translator = new FiberScopedTranslator(self::inner());

        $request = RequestFiber::start(static function () use ($translator): array {
            $translator->setLocale('en');

            return [
                $translator->trans('hello', [], null, 'ru'),
                $translator->getCatalogue('ru')->getLocale(),
            ];
        });

        self::assertSame(['Привет', 'ru'], $request->result());
    }

    public function test_an_empty_locale_argument_means_the_scoped_locale_like_stock(): void
    {
        $translator = new FiberScopedTranslator(self::inner());

        $a = RequestFiber::start(static function () use ($translator): array {
            $translator->setLocale('ru');
            \Fiber::suspend();

            // The inner's own locale is now B's, and must not leak in.
            return [$translator->trans('hello', [], null, ''), $translator->getCatalogue('')->getLocale()];
        });

        RequestFiber::start(static function () use ($translator): void {
            $translator->setLocale('en');
        });

        $a->resume();

        self::assertSame(['Привет', 'ru'], $a->result());
    }

    public function test_fresh_request_starts_at_the_boot_locale(): void
    {
        $translator = new FiberScopedTranslator(self::inner());

        $previous = RequestFiber::start(static function () use ($translator): void {
            $translator->setLocale('ru');
        });
        self::assertTrue($previous->isTerminated());

        $fresh = RequestFiber::start(static fn (): array => [$translator->getLocale(), $translator->trans('hello')]);

        self::assertSame(['en', 'Hello'], $fresh->result());
    }

    public function test_the_boot_locale_is_the_inners_locale_at_construction_time(): void
    {
        $inner = self::inner();
        $inner->setLocale('ru');

        $translator = new FiberScopedTranslator($inner);

        self::assertSame('ru', RequestFiber::start(static fn (): string => $translator->getLocale())->result());
    }

    public function test_invalid_locale_is_rejected_like_stock(): void
    {
        $translator = new FiberScopedTranslator(self::inner());

        $request = RequestFiber::start(static function () use ($translator): string {
            try {
                $translator->setLocale('a b');
            } catch (InvalidArgumentException) {
                return 'rejected ' . $translator->getLocale();
            }

            return 'accepted';
        });

        self::assertSame('rejected en', $request->result());
    }

    public function test_get_catalogue_uses_the_scoped_locale(): void
    {
        $translator = new FiberScopedTranslator(self::inner());

        $request = RequestFiber::start(static function () use ($translator): array {
            $translator->setLocale('ru');

            return [$translator->getCatalogue()->getLocale(), \count($translator->getCatalogues())];
        });

        self::assertSame(['ru', 1], $request->result());
    }

    public function test_a_fiber_the_app_creates_inside_a_request_starts_at_the_boot_locale(): void
    {
        $translator = new FiberScopedTranslator(self::inner());

        $request = RequestFiber::start(static function () use ($translator): array {
            $translator->setLocale('ru');

            $userFiber = new \Fiber(static fn (): string => $translator->getLocale());
            $userFiber->start();

            return [$translator->getLocale(), $userFiber->getReturn()];
        });

        self::assertSame(['ru', 'en'], $request->result());
    }

    public function test_a_clone_starts_from_the_current_locale_and_is_independent(): void
    {
        $translator = new FiberScopedTranslator(self::inner());

        $request = RequestFiber::start(static function () use ($translator): array {
            $translator->setLocale('ru');

            $clone = clone $translator;
            $afterClone = $clone->getLocale();

            $clone->setLocale('en');

            return [$afterClone, $clone->getLocale(), $translator->getLocale()];
        });

        self::assertSame(['ru', 'en', 'ru'], $request->result());
    }

    public function test_outside_worker_mode_it_behaves_like_the_stock_translator(): void
    {
        $translator = new FiberScopedTranslator(self::inner());

        self::assertSame('en', $translator->getLocale());
        self::assertSame('Hello', $translator->trans('hello'));

        $translator->setLocale('ru');
        self::assertSame('ru', $translator->getLocale());
        self::assertSame('Привет', $translator->trans('hello'));

        // A user-created fiber sees the same locale, in both directions.
        $fiber = new \Fiber(static function () use ($translator): string {
            $seen = $translator->getLocale();
            $translator->setLocale('en');

            return $seen;
        });
        $fiber->start();

        self::assertSame('ru', $fiber->getReturn());
        self::assertSame('en', $translator->getLocale());
    }

    public function test_it_forwards_fallback_locales_and_global_parameters_to_the_inner(): void
    {
        $inner = self::inner();
        $inner->setFallbackLocales(['en']);
        $inner->addGlobalParameter('%app%', 'oxphp');
        $translator = new FiberScopedTranslator($inner);

        self::assertSame(['en'], $translator->getFallbackLocales());
        self::assertSame(['%app%' => 'oxphp'], $translator->getGlobalParameters());
    }

    public function test_it_forwards_unknown_methods_to_the_inner(): void
    {
        $inner = self::inner();
        $translator = new FiberScopedTranslator($inner);

        $translator->setFallbackLocales(['ru']);

        self::assertSame(['ru'], $inner->getFallbackLocales());
    }

    public function test_the_data_collector_translator_still_sees_the_inners_fallback_locales_and_global_parameters(): void
    {
        $inner = self::inner();
        $inner->setFallbackLocales(['en']);
        $inner->addGlobalParameter('%app%', 'oxphp');

        // The stock behaviour the profiler's translation panel relies on.
        $stock = new DataCollectorTranslator($inner);
        $scoped = new DataCollectorTranslator(new FiberScopedTranslator($inner));

        self::assertSame(['en'], $stock->getFallbackLocales());
        self::assertSame(['%app%' => 'oxphp'], $stock->getGlobalParameters());
        self::assertSame($stock->getFallbackLocales(), $scoped->getFallbackLocales());
        self::assertSame($stock->getGlobalParameters(), $scoped->getGlobalParameters());
    }

    public function test_an_inner_without_fallback_locales_or_global_parameters_yields_none(): void
    {
        $translator = new FiberScopedTranslator(self::bareInner());

        self::assertSame([], $translator->getFallbackLocales());
        self::assertSame([], $translator->getGlobalParameters());
    }

    public function test_warm_up_forwards_to_a_warmable_inner_and_is_empty_otherwise(): void
    {
        $warmable = new class () implements TranslatorInterface, TranslatorBagInterface, LocaleAwareInterface, WarmableInterface {
            /** @var list<array{string, string|null}> */
            public array $warmed = [];

            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id;
            }

            public function getLocale(): string
            {
                return 'en';
            }

            public function setLocale(string $locale): void {}

            public function getCatalogue(?string $locale = null): MessageCatalogueInterface
            {
                return new MessageCatalogue($locale ?? 'en');
            }

            public function getCatalogues(): array
            {
                return [];
            }

            public function warmUp(string $cacheDir, ?string $buildDir = null): array
            {
                $this->warmed[] = [$cacheDir, $buildDir];

                return ['warmed.php'];
            }
        };

        self::assertSame(['warmed.php'], new FiberScopedTranslator($warmable)->warmUp('/cache', '/build'));
        self::assertSame([['/cache', '/build']], $warmable->warmed);
        self::assertSame([], new FiberScopedTranslator(self::bareInner())->warmUp('/cache'));
    }

    private static function inner(): Translator
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['hello' => 'Hello'], 'en');
        $translator->addResource('array', ['hello' => 'Привет'], 'ru');

        return $translator;
    }

    /**
     * A translator with none of the optional extras (`getFallbackLocales()`, `warmUp()`).
     */
    private static function bareInner(): TranslatorInterface&TranslatorBagInterface&LocaleAwareInterface
    {
        return new class () implements TranslatorInterface, TranslatorBagInterface, LocaleAwareInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id;
            }

            public function getLocale(): string
            {
                return 'en';
            }

            public function setLocale(string $locale): void {}

            public function getCatalogue(?string $locale = null): MessageCatalogueInterface
            {
                return new MessageCatalogue($locale ?? 'en');
            }

            public function getCatalogues(): array
            {
                return [];
            }
        };
    }
}
