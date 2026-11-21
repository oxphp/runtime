<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Symfony;

use OxPHP\Runtime\Internal\Fiber\RequestScopes;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\LocaleAwareInterface;

/**
 * @internal A `LocaleSwitcher` that keeps the current locale per request scope.
 *
 * The stock switcher holds the locale in a private field shared by the whole
 * worker. Here `setLocale()` stores the locale in the caller's scope, then runs
 * the parent's fan-out unchanged (`\Locale::setDefault()`, `_locale` on the
 * request context, `setLocale()` on every locale-aware service). The store comes
 * first because the parent writes its own field before the fan-out: if a
 * locale-aware service throws, the new locale stays recorded, exactly as in stock.
 * The scoped services (translator, request context) land that fan-out in their
 * own per-scope state; everything else it reaches (the slugger, app services)
 * stays shared. The parent's private field is therefore never read: `getLocale()`
 * answers from the scope.
 *
 * `runWithLocale()` and `reset()` are overridden only to be declared here (so
 * `OverrideCheck` can prove no public method of the parent falls through to its
 * private state) and delegate to the parent. The parent reaches the locale
 * through `$this->getLocale()` and `$this->setLocale()`, so both run on the
 * caller's scope. `reset()` returns the caller to the boot locale and leaves
 * other requests alone.
 *
 * A scope that never set a locale reads the boot locale, kept in a field of
 * this class because the parent's own copy is overwritten by every `setLocale()`.
 *
 * Per-scope state is keyed by a private token object, not by `$this`, so
 * `clone $switcher` can mint a new token and give the clone the current scope's
 * locale, as `FiberScopedRequestStack` does.
 *
 * Outside worker mode there is a single shared scope, so this behaves exactly
 * like a stock `LocaleSwitcher`.
 */
final class FiberScopedLocaleSwitcher extends LocaleSwitcher
{
    private object $token;

    private readonly string $bootLocale;

    /**
     * @param iterable<LocaleAwareInterface> $localeAwareServices
     */
    public function __construct(
        string $locale,
        iterable $localeAwareServices,
        ?RequestContext $requestContext = null,
    ) {
        $this->token = new \stdClass();
        $this->bootLocale = $locale;

        parent::__construct($locale, $localeAwareServices, $requestContext);
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

    public function setLocale(string $locale): void
    {
        // Before the fan-out, as the parent writes its own field: if a locale-aware
        // service throws, the scope keeps the new locale, as it does in stock.
        RequestScopes::current()->set($this->token, $locale);

        parent::setLocale($locale);
    }

    public function getLocale(): string
    {
        $locale = RequestScopes::current()->get($this->token, fn (): string => $this->bootLocale);
        \assert(\is_string($locale));

        return $locale;
    }

    /**
     * @template T
     *
     * @param callable(string $locale):T $callback
     *
     * @return T
     */
    public function runWithLocale(string $locale, callable $callback): mixed
    {
        return parent::runWithLocale($locale, $callback);
    }

    public function reset(): void
    {
        parent::reset();
    }
}
