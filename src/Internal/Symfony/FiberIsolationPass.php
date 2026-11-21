<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Symfony;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\UsageTrackingTokenStorage;
use Symfony\Component\Security\Http\Logout\LogoutUrlGenerator;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @internal Swaps Symfony's shared per-request services for their per-request (fiber-scoped) versions.
 *
 * All or nothing: the pass first collects every reason a present service can't be
 * scoped safely (a custom class `setClass()` would clobber, a public method the
 * scoped class doesn't declare on this Symfony version, a context listener without
 * SecurityBundle's static response listener, symfony/http-kernel < 7.3). With any
 * reason it changes nothing, sets `oxphp_runtime.fiber_isolation` to `false` and
 * records the reasons in `oxphp_runtime.fiber_isolation_reason` and the container
 * log, so the runner keeps its full teardown. Otherwise it installs every scoped
 * service and sets the marker to `true`. A missing service id is skipped: routing,
 * SecurityBundle and the translator are all optional.
 *
 * It never fails the build, so `cache:warmup` keeps working after a Symfony upgrade.
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

    private const array TRANSLATOR_INTERFACES = [
        TranslatorInterface::class,
        TranslatorBagInterface::class,
        LocaleAwareInterface::class,
    ];

    public function process(ContainerBuilder $container): void
    {
        // Must stay first. `GuardedServicesResetter` implements this interface, and
        // the checks below autoload and reflect the scoped classes: on http-kernel
        // < 7.3 none of them may be touched.
        if (!\interface_exists(ServicesResetterInterface::class)) {
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
        ];

        if ($reasons !== []) {
            $this->withhold($container, $reasons);

            return;
        }

        self::install($container);

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

        return self::overrideReasons($id, $scoped, $stock, $exempt);
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

        if (!\class_exists($class)) {
            return [\sprintf('service "%s" uses %s, which does not exist', $id, $class)];
        }

        foreach (self::TRANSLATOR_INTERFACES as $interface) {
            if (!\is_a($class, $interface, true)) {
                return [\sprintf('service "%s" uses %s, which does not implement all of %s, %s and %s', $id, $class, ...self::TRANSLATOR_INTERFACES)];
            }
        }

        return [];
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
        $class = $container->getParameterBag()->resolveValue($container->getDefinition($id)->getClass());

        return \is_string($class) ? $class : null;
    }

    private static function install(ContainerBuilder $container): void
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

        if ($container->has('services_resetter')) {
            // HttpKernel's `ResettableServicePass` removes `services_resetter` later
            // when nothing is tagged `kernel.reset`; the decorator then goes with it.
            $container->register('oxphp_runtime.services_resetter', GuardedServicesResetter::class)
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
        // still shows them. Left alone when `translator` isn't FrameworkBundle's.
        if (!$container->hasDefinition('console.command.translation_debug')
            || !$container->hasAlias('translator')
            || (string) $container->getAlias('translator') !== 'translator.default') {
            return;
        }

        $command = $container->getDefinition('console.command.translation_debug');
        $translator = $command->getArguments()[0] ?? null;

        if ($translator instanceof Reference && (string) $translator === 'translator') {
            $command->replaceArgument(0, new Reference('oxphp_runtime.translator.inner'));
        }
    }
}
