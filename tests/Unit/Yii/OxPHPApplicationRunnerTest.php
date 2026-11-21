<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Yii;

use OxPHP\Runtime\Internal\Yii\ContainerBlueprint;
use OxPHP\Runtime\Tests\Stub\FakeOxRequest;
use OxPHP\Runtime\Tests\Stub\OxPHPHarness;
use OxPHP\Runtime\Tests\Stub\Yii\ArrayConfig;
use OxPHP\Runtime\Tests\Stub\Yii\ErrorHandlerStack;
use OxPHP\Runtime\Tests\Stub\Yii\EventLog;
use OxPHP\Runtime\Tests\Stub\Yii\Guard\NeedsCurrentRoute;
use OxPHP\Runtime\Tests\Stub\Yii\PerRequestProbe;
use OxPHP\Runtime\Tests\Stub\Yii\RecordingEmitter;
use OxPHP\Runtime\Tests\Stub\Yii\SharedProbe;
use OxPHP\Runtime\Tests\Stub\Yii\YiiFixture;
use OxPHP\Runtime\Yii\OxPHPApplicationRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Yiisoft\Config\ConfigInterface;
use Yiisoft\Di\Container;
use Yiisoft\ErrorHandler\ErrorHandler;
use Yiisoft\ErrorHandler\Renderer\PlainTextRenderer;
use Yiisoft\ErrorHandler\RendererProvider\CompositeRendererProvider;
use Yiisoft\ErrorHandler\RendererProvider\RendererProviderInterface;
use Yiisoft\ErrorHandler\ThrowableResponseFactory;
use Yiisoft\ErrorHandler\ThrowableResponseFactoryInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Yii\Event\InvalidEventConfigurationFormatException;

final class OxPHPApplicationRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        OxPHPHarness::reset();
        EventLog::reset();
        YiiFixture::reset();
    }

    public function test_start_and_shutdown_run_once_per_worker_and_bootstrap_and_after_emit_once_per_request(): void
    {
        $h = OxPHPHarness::instance()->setWorker(true);
        foreach ([1, 2, 3] as $i) {
            $h->pushRequest(FakeOxRequest::get('/', ['route' => 'r', 'id' => (string) $i]));
        }
        $this->underOxPHP($this->runner(config: YiiFixture::config(bootstrap: [YiiFixture::markBootstrapped()])));

        $counts = \array_count_values(EventLog::$events);
        self::assertSame('ApplicationStartup', EventLog::$events[0]);
        self::assertSame('ApplicationShutdown', \end(EventLog::$events));
        self::assertSame([1, 1, 3, 3], [$counts['ApplicationStartup'], $counts['ApplicationShutdown'], $counts['AfterEmit'], $counts['Bootstrap']]);
        self::assertSame([200, 200, 200], $h->capturedStatuses());
    }

    public function test_listed_shared_services_are_the_same_instance_across_requests(): void
    {
        $h = OxPHPHarness::instance()->setWorker(true);
        $h->pushRequest(FakeOxRequest::get('/', ['route' => 'a']))->pushRequest(FakeOxRequest::get('/', ['route' => 'b']));
        // Keeps every request's instances alive, so a per-request one can't reuse a freed object id.
        $seen = [];
        $capture = static function (ContainerInterface $c) use (&$seen): void {
            $seen[] = [$c->get(SharedProbe::class), $c->get(PerRequestProbe::class)];
        };

        $this->underOxPHP($this->runner(
            config: YiiFixture::config(bootstrap: [$capture]),
            sharedServices: [SharedProbe::class],
        ));

        [$a, $b] = \array_map(
            static fn (string $out): array => \json_decode($out, true, flags: \JSON_THROW_ON_ERROR),
            $h->capturedOutputs(),
        );
        self::assertSame($a['shared'], $b['shared']);
        self::assertSame($seen[0][0], $seen[1][0]);
        self::assertNotSame($seen[0][1], $seen[1][1]);
    }

    public function test_a_misused_shared_list_fails_at_boot_before_any_request(): void
    {
        OxPHPHarness::instance()->setWorker(true)->pushRequest(FakeOxRequest::get('/', ['route' => 'r']));

        $e = $this->thrown(fn () => $this->underOxPHP($this->runner(sharedServices: [NeedsCurrentRoute::class])));

        self::assertInstanceOf(\LogicException::class, $e);
        self::assertSame(\sprintf(
            'Shared service "%1$s" depends on per-request service "%2$s". Remove "%1$s" from sharedServices, or stop it depending on "%2$s".',
            NeedsCurrentRoute::class,
            CurrentRoute::class,
        ), $e->getMessage());
        self::assertSame([], OxPHPHarness::instance()->capturedOutputs());
        self::assertNotContains('ApplicationStartup', EventLog::$events);
    }

    public function test_a_bootstrap_entry_that_is_not_callable_fails_at_boot_before_any_request(): void
    {
        OxPHPHarness::instance()->setWorker(true)->pushRequest(FakeOxRequest::get('/', ['route' => 'r']));
        $config = YiiFixture::config(bootstrap: [static function (): void {}, 'no_such_function']);

        $e = $this->thrown(fn () => $this->underOxPHP($this->runner($config)));

        // BootstrapRunner's exception and wording, which a request would get otherwise.
        self::assertInstanceOf(\RuntimeException::class, $e);
        self::assertSame('Bootstrap callback must be callable, "string" given.', $e->getMessage());
        self::assertSame([], OxPHPHarness::instance()->capturedOutputs());
        self::assertNotContains('ApplicationStartup', EventLog::$events);
    }

    /**
     * @return iterable<string, array{bool, list<string>, string}>
     */
    public static function provideError_pages_are_verbose_in_every_request_exactly_when_debug_is_onCases(): iterable
    {
        yield 'debug' => [true, [], 'RuntimeException with message "%s"'];
        yield 'debug, ErrorHandler shared' => [true, [ErrorHandler::class], 'RuntimeException with message "%s"'];
        yield 'no debug' => [false, [], 'An internal server error occurred.'];
    }

    /**
     * @param list<string> $sharedServices
     */
    #[DataProvider('provideError_pages_are_verbose_in_every_request_exactly_when_debug_is_onCases')]
    public function test_error_pages_are_verbose_in_every_request_exactly_when_debug_is_on(bool $debug, array $sharedServices, string $expected): void
    {
        $h = OxPHPHarness::instance()->setWorker(true);
        $h->pushRequest(FakeOxRequest::get('/', ['throw' => 'one']))->pushRequest(FakeOxRequest::get('/', ['throw' => 'two']));
        // ErrorCatcher renders through the request container's ErrorHandler, as in yiisoft/app.
        $config = YiiFixture::config(di: [
            ThrowableResponseFactoryInterface::class => ThrowableResponseFactory::class,
            RendererProviderInterface::class => static fn (): RendererProviderInterface => new CompositeRendererProvider(),
        ]);

        $this->underOxPHP($this->runner(config: $config, debug: $debug, sharedServices: $sharedServices));

        self::assertSame([500, 500], $h->capturedStatuses());
        foreach (['one', 'two'] as $i => $message) {
            self::assertStringStartsWith(\sprintf($expected, $message), $h->capturedOutputs()[$i]);
            self::assertSame($debug, \str_contains($h->capturedOutputs()[$i], 'Stack trace:'));
        }
    }

    public function test_check_events_runs_once_at_boot_against_the_boot_container(): void
    {
        OxPHPHarness::instance()->setWorker(true)->pushRequest(FakeOxRequest::get('/', ['route' => 'r']));

        $e = $this->thrown(fn () => $this->underOxPHP($this->runner(
            config: YiiFixture::config(events: ['NoSuchEvent' => []]),
            checkEvents: true,
        )));

        self::assertInstanceOf(InvalidEventConfigurationFormatException::class, $e);
        self::assertStringContainsString("'NoSuchEvent'", $e->getMessage());
        self::assertSame([], OxPHPHarness::instance()->capturedOutputs());
    }

    public function test_with_container_cannot_be_used_under_oxphp(): void
    {
        OxPHPHarness::instance()->setWorker(true)->pushRequest(FakeOxRequest::get('/', ['route' => 'r']));

        $e = $this->thrown(fn () => $this->underOxPHP($this->runner()->withContainer(new Container())));

        self::assertInstanceOf(\LogicException::class, $e);
        self::assertSame(
            'OxPHPApplicationRunner builds a new container for every request from the config, so withContainer() cannot be used under OxPHP. Use withConfig() instead.',
            $e->getMessage(),
        );
        self::assertSame([], OxPHPHarness::instance()->capturedOutputs());
    }

    public function test_a_failing_bootstrap_config_read_propagates_before_start_with_the_app_error_handler_still_enabled(): void
    {
        OxPHPHarness::instance()->setWorker(true)->pushRequest(FakeOxRequest::get('/', ['route' => 'r']));
        $appHandler = new ErrorHandler(new NullLogger(), new PlainTextRenderer());
        // `yiisoft/config` builds a group when it is first read, so a broken bootstrap file throws here.
        $config = new class (YiiFixture::config(di: [ErrorHandler::class => static fn (): ErrorHandler => $appHandler])) implements ConfigInterface {
            public function __construct(private readonly ConfigInterface $config) {}

            public function get(string $group): array
            {
                return $group === 'bootstrap-web' ? throw new \RuntimeException('bootstrap config boom') : $this->config->get($group);
            }

            public function has(string $group): bool
            {
                return $this->config->has($group);
            }
        };

        try {
            $e = $this->thrown(fn () => $this->underOxPHP($this->runner(config: $config)));

            self::assertSame('bootstrap config boom', $e->getMessage());
            self::assertNotContains('ApplicationStartup', EventLog::$events);
            // Still enabled, so its exception handler renders the throwable at the top level.
            self::assertTrue(self::enabled($appHandler));
            self::assertSame([], OxPHPHarness::instance()->capturedOutputs());
        } finally {
            $appHandler->unregister();
        }
    }

    public function test_a_throwing_startup_listener_propagates_and_shutdown_still_runs(): void
    {
        OxPHPHarness::instance()->setWorker(true)->pushRequest(FakeOxRequest::get('/', ['route' => 'r']));
        EventLog::$throwOn = 'ApplicationStartup';
        $appHandler = new ErrorHandler(new NullLogger(), new PlainTextRenderer());

        try {
            $e = $this->thrown(fn () => $this->underOxPHP($this->runner(
                config: YiiFixture::config(di: [ErrorHandler::class => static fn (): ErrorHandler => $appHandler]),
            )));

            self::assertSame('listener boom', $e->getMessage());
            self::assertSame(['ApplicationStartup', 'ApplicationShutdown'], EventLog::$events);
            // A throw from inside the loop's try must reach the top level with the handler enabled, too.
            self::assertTrue(self::enabled($appHandler));
            self::assertSame([], OxPHPHarness::instance()->capturedOutputs());
        } finally {
            $appHandler->unregister();
        }
    }

    public function test_the_app_error_handler_is_unregistered_after_the_loop_returns(): void
    {
        OxPHPHarness::instance()->setWorker(true)->pushRequest(FakeOxRequest::get('/', ['route' => 'r']));
        $appHandler = new ErrorHandler(new NullLogger(), new PlainTextRenderer());

        $this->underOxPHP($this->runner(config: YiiFixture::config(di: [ErrorHandler::class => static fn (): ErrorHandler => $appHandler])));

        self::assertCount(1, OxPHPHarness::instance()->capturedOutputs());
        self::assertFalse(self::enabled($appHandler));
    }

    public function test_outside_oxphp_it_runs_http_application_runner_with_the_same_arguments(): void
    {
        // PHP_SAPI is 'cli' here, so run() takes the fallback. Non-default group names and the emitter prove they are forwarded.
        $emitter = new RecordingEmitter();
        $runner = $this->runner(
            bootstrapGroup: 'bootstrap-custom',
            eventsGroup: 'events-custom',
            diGroup: 'di-custom',
            diProvidersGroup: 'di-providers-custom',
            diDelegatesGroup: 'di-delegates-custom',
            diTagsGroup: 'di-tags-custom',
            paramsGroup: 'params-custom',
            emitter: $emitter,
            config: YiiFixture::config(suffix: 'custom', bootstrap: [YiiFixture::markBootstrapped()]),
        );

        $json = $this->outsideOxPHP($runner, $emitter);

        self::assertSame(['fallback', '7', true], [$json['route'], $json['id'], $json['bootstrapped']]);
        // HttpApplicationRunner's order: bootstrap once, against its one container, then start().
        self::assertSame(['Bootstrap', 'ApplicationStartup'], \array_slice(EventLog::$events, 0, 2));
        self::assertSame('ApplicationShutdown', \end(EventLog::$events));
        self::assertSame(0, OxPHPHarness::instance()->flushCount());
        self::assertSame([], OxPHPHarness::instance()->capturedOutputs());
    }

    public function test_outside_oxphp_a_container_set_with_with_container_is_passed_on(): void
    {
        $container = ContainerBlueprint::fromConfig(YiiFixture::config(), 'di-web', 'di-providers-web', 'di-delegates-web', 'di-tags-web')
            ->createRequestContainer();
        $emitter = new RecordingEmitter();
        // A config with no groups: only the container can serve the request.
        $runner = $this->runner(config: new ArrayConfig([]), emitter: $emitter)->withContainer($container);

        $json = $this->outsideOxPHP($runner, $emitter);

        self::assertSame(['fallback', '7'], [$json['route'], $json['id']]);
    }

    /**
     * Runs `run()` under CLI, where it takes the fallback, for a request to
     * `/?route=fallback&id=7` given through the superglobals, and returns the JSON
     * body of the one response $emitter got.
     *
     * The emitter stands in for Yii's default `SapiEmitter`: under CLI, the status
     * line its `header('HTTP/1.1 …')` sets outlives the test, and from PHP 8.5 every
     * later `http_response_code()` call warns that it has no effect.
     *
     * @return array<string, mixed>
     */
    private function outsideOxPHP(OxPHPApplicationRunner $runner, RecordingEmitter $emitter): array
    {
        $temp = new ErrorHandler(new NullLogger(), new PlainTextRenderer());
        $stack = ErrorHandlerStack::capture();
        $server = $_SERVER;
        $get = $_GET;
        $_SERVER = [
            ...$server,
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/?route=fallback&id=7',
            'QUERY_STRING' => 'route=fallback&id=7',
            'HTTP_HOST' => 'localhost',
            'SERVER_NAME' => 'localhost',
            'SERVER_PORT' => '80',
        ];
        $_GET = ['route' => 'fallback', 'id' => '7'];

        \ob_start();

        try {
            $runner->withTemporaryErrorHandler($temp)->run();
        } finally {
            $out = (string) \ob_get_clean();
            $_SERVER = $server;
            $_GET = $get;
            $temp->unregister();
            $stack->restore();
        }

        self::assertSame('', $out);
        self::assertCount(1, $emitter->responses);

        return \json_decode((string) $emitter->responses[0]->getBody(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function runner(?ConfigInterface $config = null, mixed ...$args): OxPHPApplicationRunner
    {
        // Named arguments may follow an unpack, not precede one.
        return new OxPHPApplicationRunner(...$args, rootPath: __DIR__)->withConfig($config ?? YiiFixture::config());
    }

    /**
     * Runs the OxPHP path, which `run()` never takes under CLI, with a temporary error
     * handler that renders plain text, and puts PHPUnit's handlers back afterwards.
     */
    private function underOxPHP(OxPHPApplicationRunner $r): void
    {
        $temp = new ErrorHandler(new NullLogger(), new PlainTextRenderer());
        $stack = ErrorHandlerStack::capture();

        try {
            (fn () => $this->runUnderOxPHP())->call($r->withTemporaryErrorHandler($temp));
        } finally {
            $temp->unregister();
            $stack->restore();
        }
    }

    /**
     * Whether $handler is enabled. `unregister()` disables it but leaves its closures
     * on PHP's handler stacks, and `ErrorHandler` has no getter for it.
     */
    private static function enabled(ErrorHandler $handler): bool
    {
        return new \ReflectionProperty(ErrorHandler::class, 'enabled')->getValue($handler);
    }

    private function thrown(\Closure $run): \Throwable
    {
        try {
            $run();
        } catch (\Throwable $e) {
            return $e;
        }

        self::fail('Nothing was thrown.');
    }
}
