<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Symfony;

use OxPHP\Runtime\Internal\Fiber\RequestScopes;
use OxPHP\Runtime\Internal\Symfony\FiberIsolationPass;
use OxPHP\Runtime\Internal\Symfony\FiberScopedLocaleSwitcher;
use OxPHP\Runtime\Internal\Symfony\FiberScopedLogoutUrlGenerator;
use OxPHP\Runtime\Internal\Symfony\FiberScopedRequestContext;
use OxPHP\Runtime\Internal\Symfony\FiberScopedRequestStack;
use OxPHP\Runtime\Internal\Symfony\FiberScopedTokenStorage;
use OxPHP\Runtime\Internal\Symfony\FiberScopedTranslator;
use OxPHP\Runtime\Internal\Symfony\FiberScopedUsageTrackingTokenStorage;
use OxPHP\Runtime\Internal\Symfony\GuardedServicesResetter;
use OxPHP\Runtime\Internal\Symfony\LegacyGuardedServicesResetter;
use OxPHP\Runtime\Tests\Stub\InstalledServicesResetter;
use OxPHP\Runtime\Tests\Stub\LocaleAwareConsumer;
use OxPHP\Runtime\Tests\Stub\ServicesResetter\BothInterfacesResetter;
use OxPHP\Runtime\Tests\Stub\ServicesResetter\DependencyInjectionResetter;
use OxPHP\Runtime\Tests\Stub\ServicesResetter\HttpKernelResetter;
use OxPHP\Runtime\Tests\Stub\ServicesResetter\PlainResetter;
use OxPHP\Runtime\Tests\Stub\TranslatorConsumer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface as HttpKernelServicesResetterInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\UsageTrackingTokenStorage;
use Symfony\Component\Security\Http\Firewall\ContextListener;
use Symfony\Component\Security\Http\Logout\LogoutUrlGenerator;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Component\Translation\Translator;

final class FiberIsolationPassTest extends TestCase
{
    protected function setUp(): void
    {
        RequestScopes::resetForTests();
    }

    protected function tearDown(): void
    {
        RequestScopes::resetForTests();
    }

    public function test_scopes_every_present_service_and_sets_the_marker(): void
    {
        $c = self::stockContainer();
        $c->getDefinition('translator.default')->addTag('kernel.locale_aware');

        new FiberIsolationPass()->process($c);

        self::assertTrue($c->getParameter('oxphp_runtime.fiber_isolation'));
        self::assertSame('', $c->getParameter('oxphp_runtime.fiber_isolation_reason'));
        self::assertSame([], self::passLog($c));

        self::assertSame(FiberScopedRequestStack::class, $c->getDefinition('request_stack')->getClass());
        self::assertSame(FiberScopedTokenStorage::class, $c->getDefinition('security.untracked_token_storage')->getClass());
        self::assertSame(FiberScopedLogoutUrlGenerator::class, $c->getDefinition('security.logout_url_generator')->getClass());
        self::assertSame(FiberScopedLocaleSwitcher::class, $c->getDefinition('translation.locale_switcher')->getClass());

        $tokenStorage = $c->getDefinition('security.token_storage');
        self::assertSame(FiberScopedUsageTrackingTokenStorage::class, $tokenStorage->getClass());
        self::assertSame([['method' => 'disableUsageTracking'], ['method' => 'setToken']], $tokenStorage->getTag('kernel.reset'));

        $requestContext = $c->getDefinition('oxphp_runtime.request_context');
        self::assertSame(FiberScopedRequestContext::class, $requestContext->getClass());
        self::assertSame(['.inner'], self::referenceIds($requestContext->getArguments()));
        self::assertSame(['router.request_context', null, 0], $requestContext->getDecoratedService());

        $translator = $c->getDefinition('oxphp_runtime.translator');
        self::assertSame(FiberScopedTranslator::class, $translator->getClass());
        self::assertSame(['.inner'], self::referenceIds($translator->getArguments()));
        self::assertSame(['translator.default', null, 0], $translator->getDecoratedService());
        self::assertSame([[]], $translator->getTag('kernel.locale_aware'));
        self::assertFalse($c->getDefinition('translator.default')->hasTag('kernel.locale_aware'));

        $resetter = $c->getDefinition('oxphp_runtime.services_resetter');
        self::assertSame(InstalledServicesResetter::guard(), $resetter->getClass());
        self::assertSame(['.inner'], self::referenceIds($resetter->getArguments()));
        self::assertSame(['services_resetter', null, 0, ContainerInterface::IGNORE_ON_INVALID_REFERENCE], $resetter->getDecoratedService());

        $c->compile();

        self::assertInstanceOf(FiberScopedRequestStack::class, $c->get('request_stack'));
        self::assertInstanceOf(FiberScopedRequestContext::class, $c->get('router.request_context'));
        self::assertInstanceOf(FiberScopedTranslator::class, $c->get('translator.default'));
        self::assertInstanceOf(InstalledServicesResetter::guard(), $c->get('services_resetter'));
        self::assertInstanceOf(FiberScopedUsageTrackingTokenStorage::class, $c->get('security.token_storage'));
    }

    public function test_absent_services_are_skipped(): void
    {
        $c = new ContainerBuilder();
        $c->register('request_stack', RequestStack::class)->setPublic(true);
        $c->register('services_resetter', InstalledServicesResetter::stock())
            ->setArguments([new IteratorArgument([]), []])
            ->setPublic(true);

        new FiberIsolationPass()->process($c);

        self::assertTrue($c->getParameter('oxphp_runtime.fiber_isolation'));
        self::assertSame('', $c->getParameter('oxphp_runtime.fiber_isolation_reason'));
        self::assertSame(FiberScopedRequestStack::class, $c->getDefinition('request_stack')->getClass());
        self::assertFalse($c->hasDefinition('oxphp_runtime.request_context'));
        self::assertFalse($c->hasDefinition('oxphp_runtime.translator'));
    }

    public function test_a_custom_request_stack_class_withholds_isolation_and_changes_nothing(): void
    {
        $c = self::stockContainer();
        $c->getDefinition('request_stack')->setClass(\ArrayObject::class);
        $definitions = \serialize($c->getDefinitions());
        $aliases = \serialize($c->getAliases());

        new FiberIsolationPass()->process($c);

        self::assertFalse($c->getParameter('oxphp_runtime.fiber_isolation'));
        $reason = $c->getParameter('oxphp_runtime.fiber_isolation_reason');
        self::assertSame('service "request_stack" uses ArrayObject instead of Symfony\Component\HttpFoundation\RequestStack', $reason);
        self::assertSame([FiberIsolationPass::class . ': ' . $reason], self::passLog($c));

        self::assertFalse($c->hasDefinition('oxphp_runtime.services_resetter'));
        self::assertSame(TokenStorage::class, $c->getDefinition('security.untracked_token_storage')->getClass());
        self::assertSame($definitions, \serialize($c->getDefinitions()));
        self::assertSame($aliases, \serialize($c->getAliases()));
    }

    public function test_every_reason_is_reported_in_one_run(): void
    {
        $c = self::stockContainer();
        $c->getDefinition('request_stack')->setClass(\ArrayObject::class);
        $c->getDefinition('security.untracked_token_storage')->setClass(\ArrayObject::class);
        $c->getDefinition('translator.default')->setClass(\ArrayObject::class);

        new FiberIsolationPass()->process($c);

        self::assertFalse($c->getParameter('oxphp_runtime.fiber_isolation'));
        self::assertSame(
            'service "request_stack" uses ArrayObject instead of Symfony\Component\HttpFoundation\RequestStack; '
            . 'service "security.untracked_token_storage" uses ArrayObject instead of Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage; '
            . 'service "translator.default" uses ArrayObject, which does not implement all of Symfony\Contracts\Translation\TranslatorInterface, Symfony\Component\Translation\TranslatorBagInterface and Symfony\Contracts\Translation\LocaleAwareInterface',
            $c->getParameter('oxphp_runtime.fiber_isolation_reason'),
        );
    }

    public function test_a_parameterised_class_is_resolved_before_the_check(): void
    {
        $c = self::stockContainer();
        $c->setParameter('app.request_stack.class', RequestStack::class);
        $c->getDefinition('request_stack')->setClass('%app.request_stack.class%');

        new FiberIsolationPass()->process($c);

        self::assertTrue($c->getParameter('oxphp_runtime.fiber_isolation'));
        self::assertSame(FiberScopedRequestStack::class, $c->getDefinition('request_stack')->getClass());
    }

    public function test_token_storage_alias_is_skipped(): void
    {
        $c = self::stockContainer();
        $c->removeDefinition('security.token_storage');
        $c->setAlias('security.token_storage', 'security.untracked_token_storage')->setPublic(true);

        new FiberIsolationPass()->process($c);

        self::assertTrue($c->getParameter('oxphp_runtime.fiber_isolation'));
        self::assertFalse($c->hasDefinition('security.token_storage'));
        self::assertSame('security.untracked_token_storage', (string) $c->getAlias('security.token_storage'));
        self::assertTrue($c->getAlias('security.token_storage')->isPublic());
    }

    public function test_a_custom_usage_tracking_token_storage_withholds_isolation(): void
    {
        $c = self::stockContainer();
        $c->getDefinition('security.token_storage')->setClass(\ArrayObject::class);

        new FiberIsolationPass()->process($c);

        self::assertFalse($c->getParameter('oxphp_runtime.fiber_isolation'));
        self::assertStringContainsString('security.token_storage', $c->getParameter('oxphp_runtime.fiber_isolation_reason'));
    }

    public function test_a_context_listener_without_the_static_response_listener_withholds_isolation(): void
    {
        $c = self::stockContainer();
        $c->getDefinition('security.context_listener.0')->clearTag('kernel.event_listener');

        new FiberIsolationPass()->process($c);

        self::assertFalse($c->getParameter('oxphp_runtime.fiber_isolation'));
        self::assertStringContainsString('security.context_listener.0', $c->getParameter('oxphp_runtime.fiber_isolation_reason'));
        self::assertFalse($c->hasDefinition('oxphp_runtime.services_resetter'));
    }

    public function test_a_context_listener_whose_response_listener_calls_another_method_withholds_isolation(): void
    {
        $c = self::stockContainer();
        $c->getDefinition('security.context_listener.0')
            ->clearTag('kernel.event_listener')
            ->addTag('kernel.event_listener', ['event' => 'kernel.response', 'method' => 'somethingElse'])
            ->addTag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'onKernelResponse']);

        new FiberIsolationPass()->process($c);

        self::assertFalse($c->getParameter('oxphp_runtime.fiber_isolation'));
        self::assertStringContainsString('security.context_listener.0', $c->getParameter('oxphp_runtime.fiber_isolation_reason'));
    }

    public function test_the_scoped_translator_is_the_locale_aware_service(): void
    {
        $c = self::stockContainer();
        $c->setParameter('container.behavior_describing_tags', [
            'container.do_not_inline',
            'container.service_locator',
            'container.service_subscriber',
            'kernel.event_subscriber',
            'kernel.event_listener',
            'kernel.locale_aware',
            'kernel.reset',
        ]);
        $c->getDefinition('translator.default')->addTag('kernel.locale_aware');
        $c->register('locale_consumer', LocaleAwareConsumer::class)
            ->setArguments([new TaggedIteratorArgument('kernel.locale_aware')])
            ->setPublic(true);

        new FiberIsolationPass()->process($c);
        $c->compile();

        $consumer = $c->get('locale_consumer');
        self::assertInstanceOf(LocaleAwareConsumer::class, $consumer);
        $services = \iterator_to_array($consumer->services, false);
        self::assertCount(1, $services);
        self::assertInstanceOf(FiberScopedTranslator::class, $services[0]);
    }

    public function test_the_translation_debug_command_keeps_the_stock_translator(): void
    {
        $c = self::stockContainer();
        $c->setAlias('translator', 'translator.default')->setPublic(true);
        $c->register('console.command.translation_debug', TranslatorConsumer::class)
            ->setArguments([new Reference('translator')])
            ->setPublic(true);

        new FiberIsolationPass()->process($c);
        $c->compile();

        self::assertInstanceOf(FiberScopedTranslator::class, $c->get('translator'));
        $command = $c->get('console.command.translation_debug');
        self::assertInstanceOf(TranslatorConsumer::class, $command);
        self::assertInstanceOf(Translator::class, $command->translator);
    }

    public function test_a_translation_debug_command_with_another_translator_is_left_alone(): void
    {
        $c = self::stockContainer();
        $c->setAlias('translator', 'translator.default')->setPublic(true);
        $c->register('console.command.translation_debug', TranslatorConsumer::class)
            ->setArguments([new Reference('app.translator')]);

        new FiberIsolationPass()->process($c);

        self::assertSame(['app.translator'], self::referenceIds($c->getDefinition('console.command.translation_debug')->getArguments()));

        $c = self::stockContainer();
        $c->register('app.translator', Translator::class)->setArguments(['en']);
        $c->setAlias('translator', 'app.translator')->setPublic(true);
        $c->register('console.command.translation_debug', TranslatorConsumer::class)
            ->setArguments([new Reference('translator')]);

        new FiberIsolationPass()->process($c);

        self::assertTrue($c->getParameter('oxphp_runtime.fiber_isolation'));
        self::assertSame(['translator'], self::referenceIds($c->getDefinition('console.command.translation_debug')->getArguments()));
    }

    public function test_an_aliased_service_withholds_isolation(): void
    {
        $c = self::stockContainer();
        $c->register('app.request_stack', RequestStack::class);
        $c->removeDefinition('request_stack');
        $c->setAlias('request_stack', 'app.request_stack')->setPublic(true);

        new FiberIsolationPass()->process($c);

        self::assertFalse($c->getParameter('oxphp_runtime.fiber_isolation'));
        self::assertSame(
            'service "request_stack" is an alias of "app.request_stack", not a definition',
            $c->getParameter('oxphp_runtime.fiber_isolation_reason'),
        );
        self::assertSame(RequestStack::class, $c->getDefinition('app.request_stack')->getClass());
    }

    public function test_services_resetter_removed_later_does_not_break_the_build(): void
    {
        $c = self::stockContainer();
        $c->addCompilerPass(new FiberIsolationPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION);
        $c->addCompilerPass(new class () implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->removeDefinition('services_resetter');
            }
        }, PassConfig::TYPE_BEFORE_OPTIMIZATION, -32);

        $c->compile();

        self::assertTrue($c->getParameter('oxphp_runtime.fiber_isolation'));
        self::assertFalse($c->has('services_resetter'));
        self::assertFalse($c->has('oxphp_runtime.services_resetter'));
    }

    public function test_a_services_resetter_with_the_http_kernel_interface_gets_the_legacy_guard(): void
    {
        if (!InstalledServicesResetter::inHttpKernel()) {
            self::markTestSkipped('Needs symfony/http-kernel 7.3 to 8.0: its ServicesResetterInterface is missing before 7.3 and deprecated from 8.1.');
        }

        $c = self::stockContainer();
        $c->getDefinition('services_resetter')->setClass(HttpKernelResetter::class)->setArguments([]);

        new FiberIsolationPass()->process($c);

        self::assertTrue($c->getParameter('oxphp_runtime.fiber_isolation'));
        self::assertSame(LegacyGuardedServicesResetter::class, $c->getDefinition('oxphp_runtime.services_resetter')->getClass());

        $c->compile();

        self::assertInstanceOf(LegacyGuardedServicesResetter::class, $c->get('services_resetter'));
    }

    /**
     * HttpKernel's own `ServicesResetter` on 8.1 implements both interfaces, and
     * FrameworkBundle 8.0 aliases HttpKernel's to it: the guard must keep that type.
     * A separate process, because on http-kernel 8.1 the stub loads HttpKernel's
     * deprecated interface, which must stay unloaded for the rest of the suite.
     */
    #[RunInSeparateProcess]
    #[IgnoreDeprecations]
    public function test_a_services_resetter_with_both_interfaces_gets_the_legacy_guard(): void
    {
        if (!InstalledServicesResetter::inDependencyInjection() || Kernel::VERSION_ID < 70300) {
            self::markTestSkipped('Needs symfony/dependency-injection 8.1 and symfony/http-kernel 7.3 or later: both ServicesResetterInterfaces.');
        }

        $c = self::stockContainer();
        $c->getDefinition('services_resetter')->setClass(BothInterfacesResetter::class)->setArguments([]);

        new FiberIsolationPass()->process($c);

        self::assertTrue($c->getParameter('oxphp_runtime.fiber_isolation'));
        self::assertSame(LegacyGuardedServicesResetter::class, $c->getDefinition('oxphp_runtime.services_resetter')->getClass());

        $c->compile();

        self::assertInstanceOf(HttpKernelServicesResetterInterface::class, $c->get('services_resetter'));
    }

    public function test_a_services_resetter_with_the_dependency_injection_interface_gets_the_guard(): void
    {
        if (!InstalledServicesResetter::inDependencyInjection()) {
            self::markTestSkipped('symfony/dependency-injection < 8.1 has no ServicesResetterInterface.');
        }

        $c = self::stockContainer();
        $c->getDefinition('services_resetter')->setClass(DependencyInjectionResetter::class)->setArguments([]);

        new FiberIsolationPass()->process($c);

        self::assertTrue($c->getParameter('oxphp_runtime.fiber_isolation'));
        self::assertSame(GuardedServicesResetter::class, $c->getDefinition('oxphp_runtime.services_resetter')->getClass());

        $c->compile();

        self::assertInstanceOf(GuardedServicesResetter::class, $c->get('services_resetter'));
    }

    public function test_an_aliased_services_resetter_with_the_http_kernel_interface_gets_the_legacy_guard(): void
    {
        if (!InstalledServicesResetter::inHttpKernel()) {
            self::markTestSkipped('Needs symfony/http-kernel 7.3 to 8.0: its ServicesResetterInterface is missing before 7.3 and deprecated from 8.1.');
        }

        $c = self::stockContainer();
        $c->removeDefinition('services_resetter');
        $c->register('app.services_resetter', HttpKernelResetter::class);
        $c->setAlias('services_resetter', 'app.services_resetter')->setPublic(true);

        new FiberIsolationPass()->process($c);

        self::assertTrue($c->getParameter('oxphp_runtime.fiber_isolation'));
        self::assertSame(LegacyGuardedServicesResetter::class, $c->getDefinition('oxphp_runtime.services_resetter')->getClass());

        $c->compile();

        self::assertInstanceOf(LegacyGuardedServicesResetter::class, $c->get('services_resetter'));
    }

    public function test_an_aliased_services_resetter_with_the_dependency_injection_interface_gets_the_guard(): void
    {
        if (!InstalledServicesResetter::inDependencyInjection()) {
            self::markTestSkipped('symfony/dependency-injection < 8.1 has no ServicesResetterInterface.');
        }

        $c = self::stockContainer();
        $c->removeDefinition('services_resetter');
        $c->register('app.services_resetter', DependencyInjectionResetter::class);
        $c->setAlias('services_resetter', 'app.services_resetter')->setPublic(true);

        new FiberIsolationPass()->process($c);

        self::assertTrue($c->getParameter('oxphp_runtime.fiber_isolation'));
        self::assertSame(GuardedServicesResetter::class, $c->getDefinition('oxphp_runtime.services_resetter')->getClass());

        $c->compile();

        self::assertInstanceOf(GuardedServicesResetter::class, $c->get('services_resetter'));
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function provideAn_aliased_services_resetter_that_resolves_to_nothing_is_left_aloneCases(): iterable
    {
        yield 'alias of a missing service' => [['services_resetter' => 'app.missing']];

        yield 'circular alias' => [['services_resetter' => 'app.services_resetter', 'app.services_resetter' => 'services_resetter']];
    }

    /**
     * @param array<string, string> $aliases
     */
    #[DataProvider('provideAn_aliased_services_resetter_that_resolves_to_nothing_is_left_aloneCases')]
    public function test_an_aliased_services_resetter_that_resolves_to_nothing_is_left_alone(array $aliases): void
    {
        $c = self::stockContainer();
        $c->removeDefinition('services_resetter');
        foreach ($aliases as $alias => $target) {
            $c->setAlias($alias, $target);
        }

        new FiberIsolationPass()->process($c);

        self::assertTrue($c->getParameter('oxphp_runtime.fiber_isolation'));
        self::assertSame('', $c->getParameter('oxphp_runtime.fiber_isolation_reason'));
        self::assertFalse($c->hasDefinition('oxphp_runtime.services_resetter'));
    }

    public function test_a_services_resetter_with_neither_interface_withholds_isolation_and_changes_nothing(): void
    {
        $c = self::stockContainer();
        $c->getDefinition('services_resetter')->setClass(PlainResetter::class)->setArguments([]);
        $definitions = \serialize($c->getDefinitions());
        $aliases = \serialize($c->getAliases());

        new FiberIsolationPass()->process($c);

        self::assertFalse($c->getParameter('oxphp_runtime.fiber_isolation'));
        $reason = $c->getParameter('oxphp_runtime.fiber_isolation_reason');
        self::assertSame(
            'service "services_resetter" uses OxPHP\Runtime\Tests\Stub\ServicesResetter\PlainResetter, which implements neither '
            . 'Symfony\Component\DependencyInjection\ServicesResetterInterface nor Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface',
            $reason,
        );
        self::assertSame([FiberIsolationPass::class . ': ' . $reason], self::passLog($c));

        self::assertFalse($c->hasDefinition('oxphp_runtime.services_resetter'));
        self::assertSame($definitions, \serialize($c->getDefinitions()));
        self::assertSame($aliases, \serialize($c->getAliases()));
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function provideA_services_resetter_without_a_loadable_class_withholds_isolationCases(): iterable
    {
        yield 'missing class' => ['App\MissingResetter', 'service "services_resetter" uses App\MissingResetter, which does not exist'];

        yield 'no class' => [null, 'service "services_resetter" has no class'];
    }

    #[DataProvider('provideA_services_resetter_without_a_loadable_class_withholds_isolationCases')]
    public function test_a_services_resetter_without_a_loadable_class_withholds_isolation(?string $class, string $reason): void
    {
        $c = self::stockContainer();
        $c->getDefinition('services_resetter')->setClass($class);

        new FiberIsolationPass()->process($c);

        self::assertFalse($c->getParameter('oxphp_runtime.fiber_isolation'));
        self::assertSame($reason, $c->getParameter('oxphp_runtime.fiber_isolation_reason'));
        self::assertFalse($c->hasDefinition('oxphp_runtime.services_resetter'));
    }

    /**
     * Public stock definitions for every service id the pass knows, wired as FrameworkBundle and SecurityBundle do.
     */
    private static function stockContainer(): ContainerBuilder
    {
        $c = new ContainerBuilder();

        $c->register('request_stack', RequestStack::class)->setPublic(true);
        $c->register('router.request_context', RequestContext::class)->setPublic(true);
        $c->register('security.untracked_token_storage', TokenStorage::class)->setPublic(true);
        $c->register('security.token_storage', UsageTrackingTokenStorage::class)
            ->setArguments([
                new Reference('security.untracked_token_storage'),
                new ServiceLocatorArgument(['request_stack' => new Reference('request_stack')]),
            ])
            ->addTag('kernel.reset', ['method' => 'disableUsageTracking'])
            ->addTag('kernel.reset', ['method' => 'setToken'])
            ->setPublic(true);
        $c->register('security.logout_url_generator', LogoutUrlGenerator::class)->setPublic(true);
        $c->register('security.context_listener', ContextListener::class)->setAbstract(true);
        $c->setDefinition('security.context_listener.0', new ChildDefinition('security.context_listener'))
            ->addTag('kernel.event_listener', ['event' => 'kernel.response', 'method' => 'onKernelResponse']);
        $c->register('translator.default', Translator::class)->setArguments(['en'])->setPublic(true);
        $c->register('translation.locale_switcher', LocaleSwitcher::class)->setArguments(['en', []])->setPublic(true);
        $c->register('services_resetter', InstalledServicesResetter::stock())
            ->setArguments([new IteratorArgument([]), []])
            ->setPublic(true);

        return $c;
    }

    /**
     * The ids of `$arguments`, which must all be references.
     *
     * @param array<mixed> $arguments
     *
     * @return list<string>
     */
    private static function referenceIds(array $arguments): array
    {
        return \array_map(static function (mixed $argument): string {
            self::assertInstanceOf(Reference::class, $argument);

            return (string) $argument;
        }, \array_values($arguments));
    }

    /**
     * @return list<string>
     */
    private static function passLog(ContainerBuilder $c): array
    {
        return \array_values(\array_filter(
            $c->getCompiler()->getLog(),
            static fn (string $line): bool => \str_starts_with($line, FiberIsolationPass::class . ': '),
        ));
    }
}
