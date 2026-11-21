<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Integration\Symfony\App;

use OxPHP\Runtime\Symfony\OxPHPBundle;
use Psr\Log\LogLevel;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\Log\Logger;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Logout\LogoutUrlGenerator;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A real FrameworkBundle + SecurityBundle app with `OxPHPBundle`, for overlapping-request tests.
 *
 * Every route reaches its services through `test.service_container`, so it gets
 * the same instances the bundles wired. A route pauses its request with
 * `\Fiber::suspend()` only when the query asks for it (`?suspend=1`).
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(private readonly string $varDir, bool $debug)
    {
        parent::__construct('test', $debug);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new OxPHPBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return $this->varDir . '/cache';
    }

    public function getBuildDir(): string
    {
        return $this->varDir . '/cache';
    }

    public function getLogDir(): string
    {
        return $this->varDir . '/log';
    }

    /**
     * Every test builds its container in its own var dir. A class name unique to
     * that dir stops PHP from reusing a container class an earlier test loaded,
     * whose paths (cache, sessions, logs) point into that test's removed dir.
     */
    protected function getContainerClass(): string
    {
        return parent::getContainerClass() . \hash('xxh3', $this->varDir);
    }

    public function hello(Request $request): Response
    {
        self::suspendIfAsked($request);

        $translator = $this->service('translator', TranslatorInterface::class);
        $switcher = $this->service('translation.locale_switcher', LocaleSwitcher::class);
        $router = $this->service('router', RouterInterface::class);

        return new Response(\sprintf(
            '%s|%s|%s',
            $translator->trans('hello'),
            $switcher->getLocale(),
            $router->getContext()->getParameter('_locale'),
        ));
    }

    public function stream(Request $request): StreamedResponse
    {
        $suspend = $request->query->getBoolean('suspend');

        return new StreamedResponse(static function () use ($suspend): void {
            if ($suspend) {
                \Fiber::suspend();
            }
        });
    }

    public function main(): Response
    {
        $requestStack = $this->service('request_stack', RequestStack::class);

        return new Response($requestStack->getMainRequest() === $requestStack->getCurrentRequest() ? 'own' : 'foreign');
    }

    public function login(Request $request): Response
    {
        $user = $this->service('security.user.provider.concrete.users', UserProviderInterface::class)->loadUserByIdentifier('alice');
        $this->service('security.token_storage', TokenStorageInterface::class)
            ->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        self::suspendIfAsked($request);

        return new Response('logged in');
    }

    public function logoutPath(Request $request): Response
    {
        self::suspendIfAsked($request);

        return new Response($this->service('security.logout_url_generator', LogoutUrlGenerator::class)->getLogoutPath());
    }

    public function userFiber(): Response
    {
        $requestStack = $this->service('request_stack', RequestStack::class);
        $fiber = new \Fiber(static fn (): string => $requestStack->getCurrentRequest() === null ? 'null' : 'request');
        $fiber->start();

        return new Response($fiber->getReturn());
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test' => true,
            'secret' => 'oxphp-runtime-test',
            'session' => [
                'storage_factory_id' => 'session.storage.factory.mock_file',
            ],
            'default_locale' => 'en',
            'enabled_locales' => ['en', 'ru'],
            'translator' => [
                'default_path' => '%kernel.project_dir%/translations',
            ],
            'router' => [
                'utf8' => true,
            ],
        ]);

        $container->extension('security', [
            'providers' => [
                'users' => [
                    'memory' => [
                        'users' => [
                            'alice' => ['password' => 'unused', 'roles' => ['ROLE_USER']],
                        ],
                    ],
                ],
            ],
            'firewalls' => [
                'main' => [
                    'lazy' => false,
                    'provider' => 'users',
                    'logout' => ['path' => '/logout'],
                ],
            ],
        ]);

        $services = $container->services();

        // Logs go to the test's own log dir, never to stderr.
        $services->set('logger', Logger::class)
            ->args([LogLevel::DEBUG, '%kernel.logs_dir%/test.log']);

        $services->set(ResetCounter::class)
            ->public()
            ->tag('kernel.reset', ['method' => 'reset']);
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('hello', '/{_locale}/hello')
            ->controller([$this, 'hello'])
            ->requirements(['_locale' => 'en|ru']);
        $routes->add('stream', '/stream')->controller([$this, 'stream']);
        $routes->add('main', '/main')->controller([$this, 'main']);
        $routes->add('login', '/login')->controller([$this, 'login']);
        $routes->add('logout_path', '/logout-path')->controller([$this, 'logoutPath']);
        $routes->add('user_fiber', '/user-fiber')->controller([$this, 'userFiber']);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return T
     */
    private function service(string $id, string $type): object
    {
        $container = $this->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);

        $service = $container->get($id);
        \assert($service instanceof $type);

        return $service;
    }

    private static function suspendIfAsked(Request $request): void
    {
        if ($request->query->getBoolean('suspend')) {
            \Fiber::suspend();
        }
    }
}
