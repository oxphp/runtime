<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Stub;

use OxPHP\Runtime\Tests\Stub\FakeOxRequest;
use OxPHP\Runtime\Tests\Stub\OxPHPHarness;
use PHPUnit\Framework\TestCase;

final class OxPHPHarnessTest extends TestCase
{
    protected function setUp(): void
    {
        OxPHPHarness::reset();
    }

    public function test_worker_flag_defaults_false(): void
    {
        self::assertFalse(\oxphp_is_worker());
    }

    public function test_set_worker_flag(): void
    {
        OxPHPHarness::instance()->setWorker(true);
        self::assertTrue(\oxphp_is_worker());
    }

    public function test_drive_worker_invokes_handler_per_request_and_clears_output(): void
    {
        $harness = OxPHPHarness::instance();
        $harness->setWorker(true);
        $harness->pushRequest(FakeOxRequest::get('/a'));
        $harness->pushRequest(FakeOxRequest::get('/b'));

        $seen = [];
        \oxphp_worker(static function () use (&$seen): void {
            $req = \oxphp_http_request();
            $seen[] = $req->path();
            echo 'out:' . $req->path();
        });

        self::assertSame(['/a', '/b'], $seen);
        self::assertSame(['out:/a', 'out:/b'], $harness->capturedOutputs());
    }

    public function test_drive_worker_captures_status_per_request_and_starts_each_at_200(): void
    {
        \http_response_code(503); // leftover from an earlier test must not leak in

        $harness = OxPHPHarness::instance();
        $harness->setWorker(true);
        $harness->pushRequest(FakeOxRequest::get('/ok'));
        $harness->pushRequest(FakeOxRequest::get('/missing'));
        $harness->pushRequest(FakeOxRequest::get('/ok'));

        \oxphp_worker(static function (): void {
            if (\oxphp_http_request()->path() === '/missing') {
                \http_response_code(404);
            }
        });

        self::assertSame([200, 404, 200], $harness->capturedStatuses());
        self::assertSame(200, \http_response_code());
    }

    public function test_request_id_and_worker_id(): void
    {
        OxPHPHarness::instance()->setRequestId('abc123')->setWorkerId(7);
        self::assertSame('abc123', \oxphp_request_id());
        self::assertSame(7, \oxphp_worker_id());
    }

    public function test_stream_flush_counter(): void
    {
        $h = OxPHPHarness::instance();
        \oxphp_stream_flush();
        \oxphp_stream_flush();
        self::assertSame(2, $h->flushCount());
    }

    public function test_shutdown_callbacks_run_at_the_end_of_their_own_iteration(): void
    {
        $harness = OxPHPHarness::instance();
        $harness->setWorker(true);
        $harness->pushRequest(FakeOxRequest::get('/a'));
        $harness->pushRequest(FakeOxRequest::get('/b'));

        $log = [];
        \oxphp_worker(static function () use ($harness, &$log): void {
            $path = \oxphp_http_request()->path();

            try {
                $harness->registerShutdown(static function () use ($path, &$log): void {
                    $log[] = 'shutdown:' . $path;
                    echo '+shutdown';
                });
                $log[] = 'handler:' . $path;
                echo 'out:' . $path;
            } finally {
                $log[] = 'finally:' . $path;
            }
        });

        self::assertSame(
            ['handler:/a', 'finally:/a', 'shutdown:/a', 'handler:/b', 'finally:/b', 'shutdown:/b'],
            $log,
        );
        self::assertSame(['out:/a+shutdown', 'out:/b+shutdown'], $harness->capturedOutputs());
        self::assertSame(0, $harness->bailouts());
    }

    public function test_registering_a_shutdown_callback_outside_an_iteration_is_refused(): void
    {
        $this->expectException(\LogicException::class);

        OxPHPHarness::instance()->registerShutdown(static function (): void {});
    }

    public function test_a_suspend_in_bailout_mode_ends_the_request_without_its_finally(): void
    {
        $harness = OxPHPHarness::instance();
        $harness->setWorker(true)->setBailoutMode(true);
        $harness->pushRequest(FakeOxRequest::get('/bail'));
        $harness->pushRequest(FakeOxRequest::get('/ok'));

        $log = [];
        $level = \ob_get_level();
        \oxphp_worker(static function () use ($harness, &$log): void {
            $path = \oxphp_http_request()->path();
            $harness->registerShutdown(static function () use ($path, &$log): void {
                $log[] = 'shutdown:' . $path;
            });

            try {
                echo 'out:' . $path;
                if ($path === '/bail') {
                    // A buffer the request leaves open must not unbalance the stack.
                    \ob_start();
                    echo '+partial';
                    \Fiber::suspend();
                }
                $log[] = 'handler:' . $path;
            } finally {
                $log[] = 'finally:' . $path;
                echo 'finally output';
            }
        });

        self::assertSame(['shutdown:/bail', 'handler:/ok', 'finally:/ok', 'shutdown:/ok'], $log);
        self::assertSame(['out:/bail+partial', 'out:/okfinally output'], $harness->capturedOutputs());
        self::assertSame(1, $harness->bailouts());
        self::assertSame($level, \ob_get_level());

        // Releasing the parked request runs its finally, quietly and only once.
        \ob_start();
        OxPHPHarness::reset();
        OxPHPHarness::reset();
        $leaked = (string) \ob_get_clean();

        self::assertSame('', $leaked);
        self::assertSame(['shutdown:/bail', 'handler:/ok', 'finally:/ok', 'shutdown:/ok', 'finally:/bail'], $log);
    }

    public function test_releasing_a_parked_request_swallows_what_its_cleanup_throws_or_logs(): void
    {
        $harness = OxPHPHarness::instance();
        $harness->setWorker(true)->setBailoutMode(true);
        $harness->pushRequest(FakeOxRequest::get('/bail'));

        \oxphp_worker(static function (): void {
            try {
                \Fiber::suspend();
            } finally {
                \error_log('cleanup of a parked request');

                throw new \RuntimeException('cleanup failed');
            }
        });

        $logFile = (string) \tempnam(\sys_get_temp_dir(), 'oxphp-harness-');
        $previousLog = \ini_get('error_log');
        \ini_set('error_log', $logFile);

        try {
            OxPHPHarness::reset();
            $logged = (string) \file_get_contents($logFile);
        } finally {
            \ini_set('error_log', $previousLog === false ? '' : $previousLog);
            \unlink($logFile);
        }

        self::assertSame('', $logged);
        self::assertSame(0, OxPHPHarness::instance()->bailouts());
    }

    public function test_a_suspend_outside_bailout_mode_still_throws(): void
    {
        $harness = OxPHPHarness::instance();
        $harness->setWorker(true);
        $harness->pushRequest(FakeOxRequest::get('/a'));

        $this->expectException(\LogicException::class);

        \oxphp_worker(static function (): void {
            \Fiber::suspend();
        });
    }
}
