<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Symfony;

use OxPHP\Runtime\Internal\Fiber\RequestScopes;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @internal A translator decorator that keeps the locale per request scope.
 *
 * The stock translator holds one locale for the whole worker, so with overlapping
 * requests the last `setLocale()` wins for everybody. Here `setLocale()` still
 * calls the inner (it validates the locale and keeps the inner's own state
 * coherent) and then stores the locale in the caller's scope. `trans()` and
 * `getCatalogue()` pass the scope's locale into the inner explicitly, so the
 * inner's shared locale is never consulted for a request that has one.
 *
 * A scope that never set a locale reads the boot locale, captured from the inner
 * in the constructor, never the inner's current locale (which is whichever
 * request wrote last). One that set `''` reads the stock fallback.
 *
 * The inner may itself be wrapped (logging, the data collector in debug).
 * `DataCollectorTranslator` probes the translator it wraps with `method_exists()`
 * for `getFallbackLocales()` and `getGlobalParameters()`, and `__call()` does not
 * satisfy that probe, so both are declared here and forward to the inner when it
 * has them. Any other method is forwarded by `__call()`.
 *
 * Per-scope state is keyed by a private token object, not by `$this`, so
 * `clone $translator` can mint a new token and give the clone the current
 * scope's locale, as `FiberScopedRequestStack` does.
 *
 * Outside worker mode there is a single shared scope, so this behaves exactly
 * like the inner translator.
 */
final class FiberScopedTranslator implements TranslatorInterface, TranslatorBagInterface, LocaleAwareInterface, WarmableInterface
{
    private object $token;

    private readonly string $bootLocale;

    public function __construct(
        private readonly TranslatorInterface&TranslatorBagInterface&LocaleAwareInterface $inner,
    ) {
        $this->token = new \stdClass();
        $this->bootLocale = $inner->getLocale();
    }

    /**
     * The clone gets its own token and the current scope's locale, so changing
     * one never changes the other.
     */
    public function __clone()
    {
        $locale = $this->getLocale();

        $this->token = new \stdClass();

        RequestScopes::current()->set($this->token, $locale);
    }

    public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return $this->inner->trans((string) $id, $parameters, $domain, $locale ?: $this->getLocale());
    }

    public function setLocale(string $locale): void
    {
        $this->inner->setLocale($locale);

        RequestScopes::current()->set($this->token, $locale);
    }

    /**
     * An empty locale falls back as stock `Translator::getLocale()` does, to the
     * ICU default locale (`'en'` without intl), never to the inner's shared one.
     * That default is `intl.default_locale`, which OxPHP 0.12.0+ keeps per request;
     * on older servers it is the worker's, and the last request to set it wins.
     */
    public function getLocale(): string
    {
        $locale = RequestScopes::current()->get($this->token, fn (): string => $this->bootLocale);
        \assert(\is_string($locale));

        return $locale ?: (\class_exists(\Locale::class) ? \Locale::getDefault() : 'en');
    }

    public function getCatalogue(?string $locale = null): MessageCatalogueInterface
    {
        return $this->inner->getCatalogue($locale ?: $this->getLocale());
    }

    public function getCatalogues(): array
    {
        return $this->inner->getCatalogues();
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        if ($this->inner instanceof WarmableInterface) {
            return $this->inner->warmUp($cacheDir, $buildDir);
        }

        return [];
    }

    /**
     * `DataCollectorTranslator` probes for this with `method_exists()`.
     *
     * @return string[]
     */
    public function getFallbackLocales(): array
    {
        if (\method_exists($this->inner, 'getFallbackLocales')) {
            return $this->inner->getFallbackLocales();
        }

        return [];
    }

    /**
     * `DataCollectorTranslator` probes for this with `method_exists()`.
     *
     * @return array<string, string|int|float|TranslatableInterface>
     */
    public function getGlobalParameters(): array
    {
        if (\method_exists($this->inner, 'getGlobalParameters')) {
            return $this->inner->getGlobalParameters();
        }

        return [];
    }

    /**
     * @param array<mixed> $args
     */
    public function __call(string $method, array $args): mixed
    {
        return $this->inner->{$method}(...$args);
    }
}
