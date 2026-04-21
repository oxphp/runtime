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
        \oxphp_worker(function () use (&$seen): void {
            $req  = \oxphp_http_request();
            $seen[] = $req->path();
            echo 'out:'.$req->path();
        });

        self::assertSame(['/a', '/b'], $seen);
        self::assertSame(['out:/a', 'out:/b'], $harness->capturedOutputs());
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
}
