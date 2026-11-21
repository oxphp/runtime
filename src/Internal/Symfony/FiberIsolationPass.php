<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Symfony;

use Symfony\Bundle\FrameworkBundle\Translation\Translator as FrameworkTranslator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface as LegacyServicesResetterInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\UsageTrackingTokenStorage;
use Symfony\Component\Security\Http\Logout\LogoutUrlGenerator;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Component\Translation\Translator;

/**
 * @internal Swaps Symfony's shared per-request services for their per-request (fiber-scoped) versions.
 *
 * All or nothing: the pass first collects every reason a present service can't be
 * scoped safely (a custom class `setClass()` would clobber or a decorator would
 * hide, a `translator` alias of anything but `translator.default`, a public method
 * the scoped class doesn't declare on this Symfony version, a scoped constructor
 * that doesn't take what the stock one does (`setClass()` keeps the definition's
 * arguments), a context listener without SecurityBundle's static response
 * listener, a `services_resetter` class that implements neither
 * `ServicesResetterInterface`, symfony/http-kernel < 7.3). With any reason it
 * changes nothing, sets `oxphp_runtime.fiber_isolation` to `false` and records the
 * reasons in `oxphp_runtime.fiber_isolation_reason` and the container log, so the
 * runner keeps its full teardown. Otherwise it installs every scoped
 * service and sets the marker to `true`. A missing service id is skipped: routing,
 * SecurityBundle and the translator are all optional.
 *
 * The `services_resetter` guard follows the interface the decorated class implements:
 * `LegacyGuardedServicesResetter` for a class that implements symfony/http-kernel's
 * (7.3 on), even when it implements symfony/dependency-injection's too, and
 * `GuardedServicesResetter` for one that implements only DependencyInjection's (8.1
 * moved `ServicesResetterInterface` there). Which one Symfony registers depends on
 * the bundles, not only on the installed DependencyInjection: FrameworkBundle 8.0
 * with DependencyInjection 8.1 still registers HttpKernel's.
 *
 * It withholds instead of throwing for everything it detects, so `cache:warmup`
 * keeps working after a Symfony upgrade.
 */
final class FiberIsolationPass implements CompilerPassInterface
{
    public const string MARKER = 'oxphp_runtime.fiber_isolation';

    public const string REASON = 'oxphp_runtime.fiber_isolation_reason';

    /**
     * Services scoped by `setClass()`: id => [stock class, scoped class, stock methods that keep no per-request state].
     *
     * @var array<string, array{class-string, class-string, list<string>}>
     */
    private const array REPLACED = [
        'request_stack' => [RequestStack::class, FiberScopedRequestStack::class, ['resetRequestFormats']],
        'security.untracked_token_storage' => [TokenStorage::class, FiberScopedTokenStorage::class, []],
        'security.token_storage' => [UsageTrackingTokenStorage::class, FiberScopedUsageTrackingTokenStorage::class, []],
        'security.logout_url_generator' => [LogoutUrlGenerator::class, FiberScopedLogoutUrlGenerator::class, ['registerListener']],
        'translation.locale_switcher' => [LocaleSwitcher::class, FiberScopedLocaleSwitcher::class, []],
    ];

    /**
     * The classes `translator.default` may have: FrameworkBundle's and the one it
     * extends. The decorator hides whatever else a custom class adds.
     *
     * @var list<class-string>
     */
    private const array TRANSLATOR_CLASSES = [
        FrameworkTranslator::class,
        Translator::class,
    ];

    public function process(ContainerBuilder $container): void
    {
        // Must stay first: the checks below autoload and reflect the scoped classes,
        // and on http-kernel < 7.3 none of them may be touched. The version is read
        // from `Kernel` because DependencyInjection 8.1 installs alongside any
        // http-kernel from 6.4. At least one of the two `ServicesResetterInterface`s
        // must exist for a guard to load (on 8.1 HttpKernel's extends
        // DependencyInjection's, so the Legacy guard implements both there);
        // DependencyInjection's is checked first here, since from 8.1 autoloading
        // HttpKernel's triggers a deprecation.
        if (!\class_exists(Kernel::class)
            || Kernel::VERSION_ID < 70300
            || (!\interface_exists(ServicesResetterInterface::class) && !\interface_exists(LegacyServicesResetterInterface::class))) {
            $this->withhold($container, ['symfony/http-kernel 7.3 or later is required']);

            return;
        }

        $reasons = [
            ...self::replaceable($container, 'request_stack'),
            ...self::requestContext($container),
            ...self::replaceable($container, 'security.untracked_token_storage'),
            ...self::replaceable($container, 'security.token_storage'),
            ...self::replaceable($container, 'security.logout_url_generator'),
            ...self::contextListeners($container),
            ...self::translator($container),
            ...self::replaceable($container, 'translation.locale_switcher'),
            ...self::servicesResetter($container, $resetter),
        ];

        if ($reasons !== []) {
            $this->withhold($container, $reasons);

            return;
        }

        self::install($container, $resetter);

        $container->setParameter(self::MARKER, true);
        $container->setParameter(self::REASON, '');
    }

    /**
     * @param non-empty-list<string> $reasons
     */
    private function withhold(ContainerBuilder $container, array $reasons): void
    {
        $reason = \implode('; ', $reasons);

        $container->setParameter(self::MARKER, false);
        $container->setParameter(self::REASON, $reason);
        $container->log($this, $reason);
    }

    /**
     * @return list<string>
     */
    private static function replaceable(ContainerBuilder $container, string $id): array
    {
        if (!$container->hasDefinition($id)) {
            return self::aliasReasons($container, $id);
        }

        [$stock, $scoped, $exempt] = self::REPLACED[$id];
        $class = self::classOf($container, $id);

        if ($class !== $stock) {
            return [\sprintf('service "%s" uses %s instead of %s', $id, $class ?? 'no class', $stock)];
        }

        if (!\class_exists($stock)) {
            return [\sprintf('service "%s" uses %s, which does not exist', $id, $stock)];
        }

        $reasons = self::overrideReasons($id, $scoped, $stock, $exempt);
        $differences = OverrideCheck::constructorDifferences($scoped, $stock);

        if ($differences !== []) {
            $reasons[] = \sprintf('service "%s" cannot be scoped: the constructor of %s %s', $id, $scoped, \implode(', ', $differences));
        }

        return $reasons;
    }

    /**
     * @return list<string>
     */
    private static function requestContext(ContainerBuilder $container): array
    {
        $id = 'router.request_context';

        if (!$container->hasDefinition($id)) {
            return self::aliasReasons($container, $id);
        }

        $class = self::classOf($container, $id);

        // Decorated rather than replaced: a subclass would be hidden behind a plain
        // context, its own state and methods with it.
        if ($class !== RequestContext::class) {
            return [\sprintf('service "%s" uses %s instead of %s', $id, $class ?? 'no class', RequestContext::class)];
        }

        if (!\class_exists(RequestContext::class)) {
            return [\sprintf('service "%s" cannot be scoped: %s does not exist', $id, RequestContext::class)];
        }

        return self::overrideReasons($id, FiberScopedRequestContext::class, RequestContext::class);
    }

    /**
     * SecurityBundle wires every context listener with the per-firewall dispatcher
     * plus a static `kernel.response` listener on the global one, so no request can
     * unregister another's session save. A child without that tag is wired some
     * other way, and the stock listener would not save every request's token.
     *
     * @return list<string>
     */
    private static function contextListeners(ContainerBuilder $container): array
    {
        $reasons = [];

        foreach ($container->getDefinitions() as $id => $definition) {
            if (!$definition instanceof ChildDefinition || $definition->getParent() !== 'security.context_listener') {
                continue;
            }

            foreach ($definition->getTag('kernel.event_listener') as $attributes) {
                if (($attributes['event'] ?? null) === KernelEvents::RESPONSE && ($attributes['method'] ?? null) === 'onKernelResponse') {
                    continue 2;
                }
            }

            $reasons[] = \sprintf('service "%s" has no kernel.event_listener tag for %s calling onKernelResponse', $id, KernelEvents::RESPONSE);
        }

        return $reasons;
    }

    /**
     * @return list<string>
     */
    private static function translator(ContainerBuilder $container): array
    {
        $id = 'translator.default';

        if (!$container->hasDefinition($id)) {
            return self::aliasReasons($container, $id);
        }

        $class = self::classOf($container, $id);

        if ($class === null) {
            return [\sprintf('service "%s" has no class', $id)];
        }

        // Decorated rather than replaced, like the request context.
        if (!\in_array($class, self::TRANSLATOR_CLASSES, true)) {
            return [\sprintf('service "%s" uses %s instead of %s', $id, $class, \implode(' or ', self::TRANSLATOR_CLASSES))];
        }

        if (!\class_exists($class)) {
            return [\sprintf('service "%s" uses %s, which does not exist', $id, $class)];
        }

        return self::translatorAlias($container);
    }

    /**
     * FrameworkBundle aliases `translator`, which most services are injected with,
     * to `translator.default`. Anything else would leave them a translator with a
     * shared locale. Before optimization the alias is not yet re-pointed at the
     * decorators FrameworkBundle adds in debug mode, which decorate it.
     *
     * @return list<string>
     */
    private static function translatorAlias(ContainerBuilder $container): array
    {
        $id = 'translator';

        if ($container->hasAlias($id)) {
            $target = (string) $container->getAlias($id);

            return $target === 'translator.default'
                ? []
                : [\sprintf('service "%s" is an alias of "%s" instead of "translator.default"', $id, $target)];
        }

        if ($container->hasDefinition($id)) {
            return [\sprintf('service "%s" is a definition of its own instead of an alias of "translator.default"', $id)];
        }

        return [];
    }

    /**
     * Picks the guard by the interface the `services_resetter` class implements, which
     * is the type the guard must keep for whatever is wired with that service.
     * HttpKernel's is checked first: on 8.1 it extends DependencyInjection's, so a
     * class that implements it (HttpKernel's own `ServicesResetter`, which
     * FrameworkBundle 8.0 registers) implements both and needs the legacy guard, which
     * there implements both too. `is_a()` doesn't autoload the interface, so on the
     * full 8.1 stack HttpKernel's deprecated one stays unloaded: DependencyInjection's
     * `ServicesResetter` doesn't implement it.
     *
     * An alias is followed to its definition for the class. `DecoratorServicePass`
     * then re-points the `services_resetter` alias at the guard and aliases `.inner`
     * to the old target; the target definition itself is not decorated, so a reference
     * to the target's own id bypasses the guard. A missing service, or an alias that
     * resolves to none, leaves `$resetter` `null`: there is nothing to decorate.
     *
     * @param-out class-string<AbstractGuardedServicesResetter>|null $resetter
     *
     * @return list<string>
     */
    private static function servicesResetter(ContainerBuilder $container, ?string &$resetter): array
    {
        $id = 'services_resetter';
        $resetter = null;

        if (!$container->hasDefinition($id) && !$container->hasAlias($id)) {
            return [];
        }

        try {
            $class = self::classOf($container, $id);
        } catch (ServiceCircularReferenceException|ServiceNotFoundException) {
            return [];
        }

        if ($class === null) {
            return [\sprintf('service "%s" has no class', $id)];
        }

        if (!\class_exists($class)) {
            return [\sprintf('service "%s" uses %s, which does not exist', $id, $class)];
        }

        if (\is_a($class, LegacyServicesResetterInterface::class, true)) {
            $resetter = LegacyGuardedServicesResetter::class;

            return [];
        }

        if (\is_a($class, ServicesResetterInterface::class, true)) {
            $resetter = GuardedServicesResetter::class;

            return [];
        }

        return [\sprintf('service "%s" uses %s, which implements neither %s nor %s', $id, $class, ServicesResetterInterface::class, LegacyServicesResetterInterface::class)];
    }

    /**
     * An id defined as an alias can't be checked or replaced here, so it withholds
     * isolation. The exception is SecurityBundle's own alias of `security.token_storage`
     * (without `session.factory`) to `security.untracked_token_storage`, which is
     * scoped itself.
     *
     * @return list<string>
     */
    private static function aliasReasons(ContainerBuilder $container, string $id): array
    {
        if (!$container->hasAlias($id)) {
            return [];
        }

        $target = (string) $container->getAlias($id);

        if ($id === 'security.token_storage' && $target === 'security.untracked_token_storage') {
            return [];
        }

        return [\sprintf('service "%s" is an alias of "%s", not a definition', $id, $target)];
    }

    /**
     * @param class-string $scoped
     * @param class-string $stock
     * @param list<string> $exempt
     *
     * @return list<string>
     */
    private static function overrideReasons(string $id, string $scoped, string $stock, array $exempt = []): array
    {
        $missing = OverrideCheck::missing($scoped, $stock, $exempt);

        if ($missing === []) {
            return [];
        }

        $methods = \array_map(static fn (string $method): string => $stock . '::' . $method . '()', $missing);

        return [\sprintf('service "%s" cannot be scoped: %s does not declare %s', $id, $scoped, \implode(', ', $methods))];
    }

    private static function classOf(ContainerBuilder $container, string $id): ?string
    {
        $class = $container->getParameterBag()->resolveValue($container->findDefinition($id)->getClass());

        return \is_string($class) ? $class : null;
    }

    /**
     * @param class-string<AbstractGuardedServicesResetter>|null $resetter the `services_resetter` guard, `null` for none
     */
    private static function install(ContainerBuilder $container, ?string $resetter): void
    {
        foreach (self::REPLACED as $id => [, $scoped]) {
            if ($container->hasDefinition($id)) {
                // Tags stay: `kernel.reset` methods exist on every scoped class.
                $container->getDefinition($id)->setClass($scoped);
            }
        }

        if ($container->hasDefinition('router.request_context')) {
            $container->register('oxphp_runtime.request_context', FiberScopedRequestContext::class)
                ->setArguments([new Reference('.inner')])
                ->setDecoratedService('router.request_context');
        }

        if ($container->hasDefinition('translator.default')) {
            self::installTranslator($container);
        }

        if ($resetter !== null) {
            // `ResettableServicePass` (HttpKernel's, DependencyInjection's from 8.1)
            // removes `services_resetter` later when nothing is tagged `kernel.reset`;
            // the decorator then goes with it.
            $container->register('oxphp_runtime.services_resetter', $resetter)
                ->setArguments([new Reference('.inner')])
                ->setDecoratedService('services_resetter', null, 0, ContainerInterface::IGNORE_ON_INVALID_REFERENCE);
        }
    }

    private static function installTranslator(ContainerBuilder $container): void
    {
        $inner = $container->getDefinition('translator.default');
        $decorator = $container->register('oxphp_runtime.translator', FiberScopedTranslator::class)
            ->setArguments([new Reference('.inner')])
            ->setDecoratedService('translator.default');

        // The locale switcher's `tagged_iterator('kernel.locale_aware')` must reach the
        // scoped decorator, not the shared inner translator. `DecoratorServicePass` keeps
        // the tags named in `container.behavior_describing_tags` (FrameworkBundle lists
        // `kernel.locale_aware`) on the inner service. On symfony/dependency-injection 8.0
        // `ResolveInstanceofConditionalsPass` removes that parameter before decoration, so
        // the tag would move anyway; moving it here doesn't depend on that.
        foreach ($inner->getTag('kernel.locale_aware') as $attributes) {
            $decorator->addTag('kernel.locale_aware', $attributes);
        }
        $inner->clearTag('kernel.locale_aware');

        // `TranslationDebugCommand` loads fallback catalogues only for a concrete
        // `Translator`, `DataCollectorTranslator` or `LoggingTranslator`. Give it the
        // stock translator under the decorator, so `debug:translation --no-debug`
        // still shows them. A `translator` alias reaching here targets
        // `translator.default` (see `translatorAlias()`).
        if (!$container->hasDefinition('console.command.translation_debug') || !$container->hasAlias('translator')) {
            return;
        }

        $command = $container->getDefinition('console.command.translation_debug');
        $translator = $command->getArguments()[0] ?? null;

        if ($translator instanceof Reference && (string) $translator === 'translator') {
            $command->replaceArgument(0, new Reference('oxphp_runtime.translator.inner'));
        }
    }
}
