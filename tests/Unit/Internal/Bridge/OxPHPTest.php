<?php
declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Bridge;

use OxPHP\Runtime\Internal\Bridge\OxPHP;
use OxPHP\Runtime\Tests\Stub\FakeOxRequest;
use OxPHP\Runtime\Tests\Stub\OxPHPHarness;
use PHPUnit\Framework\TestCase;

final class OxPHPTest extends TestCase
{
    protected function setUp(): void
    {
        OxPHPHarness::reset();
    }

    public function test_is_worker_reflects_harness(): void
    {
        $bridge = new OxPHP();
        self::assertFalse($bridge->isWorker());
        OxPHPHarness::instance()->setWorker(true);
        self::assertTrue($bridge->isWorker());
    }

    public function test_current_request_returns_live_proxy(): void
    {
        $req = FakeOxRequest::get('/hello');
        OxPHPHarness::instance()->setCurrentRequest($req);

        self::assertSame($req, (new OxPHP())->currentRequest());
    }

    public function test_stream_flush_increments_harness_counter(): void
    {
        (new OxPHP())->streamFlush();
        (new OxPHP())->streamFlush();
        self::assertSame(2, OxPHPHarness::instance()->flushCount());
    }

    public function test_finish_request_increments_harness_counter(): void
    {
        self::assertTrue((new OxPHP())->finishRequest());
        self::assertSame(1, OxPHPHarness::instance()->finishCount());
    }
}
