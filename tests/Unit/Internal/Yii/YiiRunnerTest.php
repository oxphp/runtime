<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Yii;

use Nyholm\Psr7\Factory\Psr17Factory;
use OxPHP\Runtime\Internal\Bridge\OxPHP;
use OxPHP\Runtime\Internal\Yii\ContainerBlueprint;
use OxPHP\Runtime\Internal\Yii\SharedServices;
use OxPHP\Runtime\Internal\Yii\YiiRunner;
use OxPHP\Runtime\Tests\Stub\FakeOxRequest;
use OxPHP\Runtime\Tests\Stub\OxPHPHarness;
use OxPHP\Runtime\Tests\Stub\RequestFiber;
use OxPHP\Runtime\Tests\Stub\RunsUnderPhpCgi;
use OxPHP\Runtime\Tests\Stub\Yii\EventLog;
use OxPHP\Runtime\Tests\Stub\Yii\RecordingEmitter;
use OxPHP\Runtime\Tests\Stub\Yii\SharedProbe;
use OxPHP\Runtime\Tests\Stub\Yii\YiiFixture;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\PsrEmitter\EmitterInterface;
use Yiisoft\Yii\Http\Application;

final class YiiRunnerTest extends TestCase
{
    use RunsUnderPhpCgi;

    protected function setUp(): void
    {
        OxPHPHarness::reset();
        EventLog::reset();
        YiiFixture::reset();
    }

    public function test_overlapping_requests_each_see_their_own_route_and_services(): void
    {
        $runner = $this->runner();
        $a = RequestFiber::start(fn (): string => $this->handle($runner, FakeOxRequest::get('/', ['route' => 'a', 'id' => '1', 'suspend' => '1'])));
        $b = RequestFiber::start(fn (): string => $this->handle($runner, FakeOxRequest::get('/', ['route' => 'b', 'id' => '2'])));
        $a->resume();

        $ra = \json_decode($a->result(), true, flags: \JSON_THROW_ON_ERROR);
        $rb = \json_decode($b->result(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['a', '1'], [$ra['route'], $ra['id']]);
        self::assertSame(['b', '2'], [$rb['route'], $rb['id']]);
        self::assertNotSame($ra['probe'], $rb['probe']);
        self::assertSame($ra['shared'], $rb['shared']);
        self::assertSame(1_700_000_000.0, $ra['start']);
    }

    public function test_control_one_container_for_both_overlapping_requests_throws(): void
    {
        // What a container shared between requests does: both are served by one
        // `Application`, so both route through one `CurrentRoute`.
        $app = self::blueprint()->createRequestContainer()->get(Application::class);
        $f = new Psr17Factory();
        $request = static fn (array $query): ServerRequestInterface => $f->createServerRequest('GET', '/')->withQueryParams($query);

        $a = RequestFiber::start(static fn (): int => $app->handle($request(['route' => 'a', 'id' => '1', 'suspend' => '1']))->getStatusCode());
        $b = RequestFiber::start(static function () use ($app, $request): ?\Throwable {
            try {
                $app->handle($request(['route' => 'b', 'id' => '2']));

                return null;
            } catch (\Throwable $e) {
                return $e;
            }
        });
        $a->resume();

        $thrown = $b->result();
        self::assertInstanceOf(\LogicException::class, $thrown);
        self::assertSame('Can not set route/arguments since it was already set.', $thrown->getMessage());
        self::assertSame(200, $a->result());
    }

    public function test_a_throwing_handler_gets_the_error_catcher_response(): void
    {
        $h = OxPHPHarness::instance()->setWorker(true);
        $h->pushRequest(FakeOxRequest::get('/', ['throw' => 'boom']))->pushRequest(FakeOxRequest::get('/', ['route' => 'ok']));

        $this->runner()->run();

        self::assertSame([500, 200], $h->capturedStatuses());
        self::assertSame('caught: boom', $h->capturedOutputs()[0]);
    }

    public function test_a_body_that_fails_to_build_before_anything_is_sent_gets_the_error_catcher_response(): void
    {
        // A Yii view renders when its body is first read, after handle() has returned;
        // HttpApplicationRunner still answers such a failure with ErrorCatcher's response.
        $h = OxPHPHarness::instance()->setWorker(true);
        $h->pushRequest(FakeOxRequest::get('/', ['route' => 'view', 'render_throw' => 'view boom']))->pushRequest(FakeOxRequest::get('/', ['route' => 'ok']));

        $this->runner()->run();

        self::assertSame([500, 200], $h->capturedStatuses());
        self::assertSame('caught: view boom', $h->capturedOutputs()[0]);
        self::assertSame(2, \array_count_values(EventLog::$events)['AfterEmit']);
    }

    public function test_when_the_error_catcher_throws_too_the_fallback_500_is_sent_and_the_next_request_is_served(): void
    {
        $h = OxPHPHarness::instance()->setWorker(true);
        $h->pushRequest(FakeOxRequest::get('/', ['throw' => 'fatal']))->pushRequest(FakeOxRequest::get('/', ['route' => 'ok']));

        $log = $this->log(fn () => $this->runner()->run());

        self::assertSame([500, 200], $h->capturedStatuses());
        self::assertSame("Internal Server Error\n", $h->capturedOutputs()[0]);
        self::assertStringContainsString('catcher boom', $log);
    }

    public function test_a_request_whose_setup_throws_gets_the_fallback_500_and_the_next_is_served(): void
    {
        $h = OxPHPHarness::instance()->setWorker(true);
        $h->pushRequest(FakeOxRequest::get('/', ['route' => 'one']))->pushRequest(FakeOxRequest::get('/', ['route' => 'two']));

        $log = $this->log(fn () => $this->runner([YiiFixture::failingOnce()])->run());

        self::assertSame([500, 200], $h->capturedStatuses());
        self::assertSame("Internal Server Error\n", $h->capturedOutputs()[0]);
        self::assertSame('two', \json_decode($h->capturedOutputs()[1], true, flags: \JSON_THROW_ON_ERROR)['route']);
        self::assertStringContainsString('setup boom', $log);
    }

    public function test_after_emit_runs_once_per_request_including_the_error_path(): void
    {
        $h = OxPHPHarness::instance()->setWorker(true);
        $h->pushRequest(FakeOxRequest::get('/', ['throw' => 'boom']))->pushRequest(FakeOxRequest::get('/', ['route' => 'ok']));

        $this->runner()->run();

        self::assertSame(2, \array_count_values(EventLog::$events)['AfterEmit']);
    }

    public function test_a_throwing_after_emit_listener_is_logged_and_leaves_the_response_alone(): void
    {
        EventLog::$throwOn = 'AfterEmit';
        $h = OxPHPHarness::instance()->setWorker(true);
        $h->pushRequest(FakeOxRequest::get('/', ['route' => 'one']))->pushRequest(FakeOxRequest::get('/', ['route' => 'two']));

        $log = $this->log(fn () => $this->runner()->run());

        self::assertSame([200, 200], $h->capturedStatuses());
        self::assertSame(['one', 'two'], \array_map(
            static fn (string $out): string => \json_decode($out, true, flags: \JSON_THROW_ON_ERROR)['route'],
            $h->capturedOutputs(),
        ));
        self::assertStringContainsString('Application::afterEmit() threw', $log);
        self::assertStringContainsString('listener boom', $log);
    }

    public function test_bootstrap_runs_against_each_request_container(): void
    {
        $h = OxPHPHarness::instance()->setWorker(true);
        $h->pushRequest(FakeOxRequest::get('/', ['route' => 'one']))->pushRequest(FakeOxRequest::get('/', ['route' => 'two']));

        $this->runner([YiiFixture::markBootstrapped()])->run();

        foreach ($h->capturedOutputs() as $out) {
            self::assertTrue(\json_decode($out, true, flags: \JSON_THROW_ON_ERROR)['bootstrapped']);
        }
        self::assertCount(2, $h->capturedOutputs());
        self::assertSame(2, \array_count_values(EventLog::$events)['Bootstrap']);
    }

    public function test_a_streamed_body_is_flushed_per_chunk(): void
    {
        $h = OxPHPHarness::instance()->setWorker(true);
        $h->pushRequest(FakeOxRequest::get('/', ['route' => 's', 'size' => '20000']));

        $this->runner()->run();

        self::assertSame(20000, \strlen($h->capturedOutputs()[0]));
        self::assertSame(3, $h->flushCount());
    }

    public function test_a_given_emitter_is_used_instead_of_the_response_emitter(): void
    {
        $emitter = new RecordingEmitter();
        $h = OxPHPHarness::instance()->setWorker(true);
        $h->pushRequest(FakeOxRequest::get('/', ['route' => 'ok']));

        $this->runner(emitter: $emitter)->run();

        self::assertCount(1, $emitter->responses);
        self::assertSame(200, $emitter->responses[0]->getStatusCode());
        self::assertSame('ok', \json_decode((string) $emitter->responses[0]->getBody(), true, flags: \JSON_THROW_ON_ERROR)['route']);
        self::assertSame('', $h->capturedOutputs()[0]);
        self::assertSame(0, $h->flushCount());
    }

    public function test_headers_php_queued_itself_are_dropped_so_only_the_response_headers_go_out(): void
    {
        $r = self::runUnderPhpCgi('emitAfterTheSessionCacheLimiter');

        self::assertSame(200, $r['status'], $r['stderr']);
        self::assertSame(['public, max-age=60'], self::headerValues($r['headers'], 'Cache-Control'));
        self::assertSame([], self::headerValues($r['headers'], 'Expires'));
        self::assertSame([], self::headerValues($r['headers'], 'Pragma'));
        self::assertSame('ok', \json_decode($r['body'], true, flags: \JSON_THROW_ON_ERROR)['route']);
    }

    /**
     * Run under php-cgi by the test above: one traditional-mode request, after PHP's session
     * cache limiter has queued its headers, as it does when a page starts the session.
     */
    public static function emitAfterTheSessionCacheLimiter(): void
    {
        \header('Expires: Thu, 19 Nov 1981 08:52:00 GMT');
        \header('Cache-Control: no-store, no-cache, must-revalidate');
        \header('Pragma: no-cache');
        OxPHPHarness::instance()->setCurrentRequest(FakeOxRequest::get('/', ['route' => 'ok', 'cache_control' => 'public, max-age=60']));

        new YiiRunner(new OxPHP(), self::blueprint(), [])->run();
    }

    /**
     * @param list<callable> $bootstrap
     */
    private function runner(array $bootstrap = [], ?EmitterInterface $emitter = null): YiiRunner
    {
        return new YiiRunner(new OxPHP(), self::blueprint($bootstrap), $bootstrap, $emitter);
    }

    /**
     * @param list<callable> $bootstrap
     */
    private static function blueprint(array $bootstrap = []): ContainerBlueprint
    {
        $bp = ContainerBlueprint::fromConfig(
            YiiFixture::config(bootstrap: $bootstrap),
            'di-web',
            'di-providers-web',
            'di-delegates-web',
            'di-tags-web',
        );

        return $bp->withSharedInstances(SharedServices::resolve($bp, [SharedProbe::class], false));
    }

    /**
     * Runs one request through the runner, outside the worker loop, and returns what it wrote.
     */
    private function handle(YiiRunner $r, FakeOxRequest $req): string
    {
        \ob_start();

        try {
            \Closure::bind(fn () => $this->handleRequest($req), $r, YiiRunner::class)();
        } finally {
            $out = (string) \ob_get_clean();
        }

        return $out;
    }

    /**
     * Runs $run with the error log pointed at a temp file, and returns what was logged.
     */
    private function log(\Closure $run): string
    {
        $logFile = (string) \tempnam(\sys_get_temp_dir(), 'oxphp-runtime-test');
        $previousLog = (string) \ini_get('error_log');
        \ini_set('error_log', $logFile);

        try {
            $run();

            return (string) \file_get_contents($logFile);
        } finally {
            \ini_set('error_log', $previousLog);
            \unlink($logFile);
        }
    }
}
