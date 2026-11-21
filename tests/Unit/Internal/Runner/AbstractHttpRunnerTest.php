<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Runner;

use OxPHP\Http\RequestInterface as OxRequest;
use OxPHP\Runtime\Internal\Bridge\OxPHP;
use OxPHP\Runtime\Internal\Fiber\RequestScopes;
use OxPHP\Runtime\Internal\Reset\ResetterChain;
use OxPHP\Runtime\Internal\Runner\AbstractHttpRunner;
use OxPHP\Runtime\Tests\Stub\FakeOxRequest;
use OxPHP\Runtime\Tests\Stub\OxPHPHarness;
use PHPUnit\Framework\TestCase;

final class AbstractHttpRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        OxPHPHarness::reset();
    }

    protected function tearDown(): void
    {
        OxPHPHarness::reset();
    }

    public function test_run_once_invokes_handle_request_and_returns_zero(): void
    {
        OxPHPHarness::instance()->setCurrentRequest(FakeOxRequest::get('/x'));

        $runner = $this->newRunner(static function (OxRequest $req, array &$state): void {
            $state[] = 'handle:' . $req->path();
            echo 'body';
        }, $state);

        \ob_start();
        $code = $runner->run();
        $out = (string) \ob_get_clean();

        self::assertSame(0, $code);
        self::assertSame('body', $out);
        self::assertSame(['handle:/x'], $state);
    }

    public function test_run_worker_iterates_requests_and_resets_between_each(): void
    {
        $h = OxPHPHarness::instance();
        $h->setWorker(true);
        $h->pushRequest(FakeOxRequest::get('/a'));
        $h->pushRequest(FakeOxRequest::get('/b'));

        $resetCalls = 0;
        $runner = $this->newRunner(
            handler: static function (OxRequest $req, array &$state): void {
                $state[] = 'h:' . $req->path();
                echo $req->path();
            },
            state: $state,
            resetters: [static function () use (&$resetCalls): void {
                $resetCalls++;
            }],
        );

        $code = $runner->run();

        self::assertSame(0, $code);
        self::assertSame(['h:/a', 'h:/b'], $state);
        self::assertSame(['/a', '/b'], $h->capturedOutputs());
        self::assertSame(2, $resetCalls);
    }

    public function test_worker_exception_emits_fallback_500_and_resets_still_runs(): void
    {
        $h = OxPHPHarness::instance();
        $h->setWorker(true);
        $h->pushRequest(FakeOxRequest::get('/boom'));

        $resets = 0;
        $runner = $this->newRunner(
            handler: static function (): void {
                throw new \RuntimeException('kaboom');
            },
            state: $state,
            resetters: [static function () use (&$resets): void {
                $resets++;
            }],
        );

        $runner->run();

        $out = $h->capturedOutputs()[0] ?? '';
        self::assertStringContainsString('Internal Server Error', $out);
        self::assertSame(1, $resets);
    }

    public function test_run_worker_puts_boot_server_vars_back_into_every_request(): void
    {
        // The soft reset hands each request a $_SERVER of its own, without
        // what the worker's boot (dotenv, APP_ENV, ...) wrote there.
        $h = OxPHPHarness::instance();
        $h->setWorker(true);
        $h->setRequestServer(['REQUEST_URI' => '/']);
        $h->pushRequest(FakeOxRequest::get('/a'));
        $h->pushRequest(FakeOxRequest::get('/b'));

        $runner = $this->newRunner(
            handler: static function (OxRequest $req, array &$state): void {
                $state[] = $_SERVER['MY_VAR'] ?? null;
            },
            state: $state,
            bootServer: ['MY_VAR' => 'hello'],
        );

        $runner->run();

        self::assertSame(['hello', 'hello'], $state);
    }

    public function test_boot_server_vars_never_override_what_the_server_set_for_the_request(): void
    {
        $h = OxPHPHarness::instance();
        $h->setWorker(true);
        $h->setRequestServer(['REQUEST_URI' => '/real', 'APP_ENV' => 'prod']);
        $h->pushRequest(FakeOxRequest::get('/real'));

        $runner = $this->newRunner(
            handler: static function (OxRequest $req, array &$state): void {
                $state = [$_SERVER['REQUEST_URI'], $_SERVER['APP_ENV'], $_SERVER['MY_VAR']];
            },
            state: $state,
            bootServer: ['REQUEST_URI' => '/', 'APP_ENV' => 'dev', 'MY_VAR' => 'hello'],
        );

        $runner->run();

        self::assertSame(['/real', 'prod', 'hello'], $state);
    }

    public function test_worker_iteration_runs_inside_an_open_request_scope(): void
    {
        $h = OxPHPHarness::instance();
        $h->setWorker(true);
        $h->pushRequest(FakeOxRequest::get('/a'));
        $h->pushRequest(FakeOxRequest::get('/b'));

        $runner = $this->newRunner(
            handler: static function (OxRequest $req, array &$state): void {
                $state[] = RequestScopes::inFlight();
            },
            state: $state,
        );

        $runner->run();

        self::assertSame([1, 1], $state);
        self::assertSame(0, RequestScopes::inFlight());
        self::assertFalse(RequestScopes::isDegraded());
    }

    public function test_resetters_run_while_the_request_scope_is_still_open(): void
    {
        $h = OxPHPHarness::instance();
        $h->setWorker(true);
        $h->pushRequest(FakeOxRequest::get('/a'));

        $seen = [];
        $runner = $this->newRunner(
            handler: static function (): void {},
            state: $state,
            resetters: [static function () use (&$seen): void {
                $seen[] = RequestScopes::inFlight();
            }],
        );

        $runner->run();

        self::assertSame([1], $seen);
    }

    public function test_worker_iteration_closes_its_scope_when_the_handler_throws(): void
    {
        $h = OxPHPHarness::instance();
        $h->setWorker(true);
        $h->pushRequest(FakeOxRequest::get('/boom'));
        $h->pushRequest(FakeOxRequest::get('/ok'));

        $runner = $this->newRunner(
            handler: static function (OxRequest $req, array &$state): void {
                if ($req->path() === '/boom') {
                    throw new \RuntimeException('kaboom');
                }

                $state[] = RequestScopes::inFlight();
            },
            state: $state,
        );

        // The runner logs the handler's exception; keep it out of the test output.
        $logFile = (string) \tempnam(\sys_get_temp_dir(), 'oxphp-runtime-test');
        $previousLog = (string) \ini_get('error_log');
        \ini_set('error_log', $logFile);

        try {
            $runner->run();
        } finally {
            \ini_set('error_log', $previousLog);
            \unlink($logFile);
        }

        self::assertSame([1], $state);
        self::assertSame(0, RequestScopes::inFlight());
        self::assertFalse(RequestScopes::hasBailedOut(), 'An exception unwinds through the finally.');
    }

    public function test_a_request_that_bails_out_still_closes_its_scope(): void
    {
        // OxPHP skips every finally of a request that hits a fatal error, a time
        // limit, exit(), a cancel, or a client gone mid-stream, and still runs the
        // request's shutdown functions.
        $h = OxPHPHarness::instance();
        $h->setWorker(true)->setBailoutMode(true);
        $h->pushRequest(FakeOxRequest::get('/bail'));
        $h->pushRequest(FakeOxRequest::get('/a'));
        $h->pushRequest(FakeOxRequest::get('/b'));

        $runner = $this->newRunner(
            handler: static function (OxRequest $req, array &$state): void {
                if ($req->path() === '/bail') {
                    \Fiber::suspend();
                }

                $state[] = RequestScopes::inFlight();
            },
            state: $state,
        );

        $runner->run();

        self::assertSame(1, $h->bailouts());
        self::assertSame([1, 1], $state, 'The requests after the bailout run alone.');
        self::assertSame(0, RequestScopes::inFlight());
        self::assertTrue(RequestScopes::hasBailedOut());
    }

    public function test_the_scope_closes_before_the_apps_own_shutdown_functions(): void
    {
        // The runner's close is the request's first shutdown function, so an app
        // callback that calls exit() or throws can't skip it.
        $h = OxPHPHarness::instance();
        $h->setWorker(true)->setBailoutMode(true);
        $h->pushRequest(FakeOxRequest::get('/bail'));

        $runner = $this->newRunner(
            handler: static function (OxRequest $req, array &$state) use ($h): void {
                $h->registerShutdown(static function () use (&$state): void {
                    $state[] = RequestScopes::inFlight();
                });

                \Fiber::suspend();
            },
            state: $state,
        );

        $runner->run();

        self::assertSame([0], $state);
    }

    public function test_requests_that_end_normally_never_mark_a_bailout(): void
    {
        $h = OxPHPHarness::instance();
        $h->setWorker(true)->setBailoutMode(true);
        $h->pushRequest(FakeOxRequest::get('/a'));
        $h->pushRequest(FakeOxRequest::get('/b'));

        $this->newRunner(handler: static function (): void {}, state: $state)->run();

        self::assertSame(0, $h->bailouts());
        self::assertSame(0, RequestScopes::inFlight());
        self::assertFalse(RequestScopes::hasBailedOut());
    }

    /**
     * @param callable(OxRequest, array): void $handler
     * @param list<callable> $resetters
     * @param array<string, mixed> $bootServer
     */
    private function newRunner(
        callable $handler,
        ?array &$state,
        array $resetters = [],
        array $bootServer = [],
    ): AbstractHttpRunner {
        $state = [];
        $bridge = new OxPHP();
        $chain = new ResetterChain($resetters);

        return new class ($bridge, $chain, $bootServer, $handler, $state) extends AbstractHttpRunner {
            public function __construct(
                OxPHP $b,
                ResetterChain $c,
                array $bootServer,
                private $handler,
                private array &$stateRef,
            ) {
                parent::__construct($b, $c, $bootServer);
            }

            protected function handleRequest(OxRequest $req): void
            {
                ($this->handler)($req, $this->stateRef);
            }
        };
    }
}
