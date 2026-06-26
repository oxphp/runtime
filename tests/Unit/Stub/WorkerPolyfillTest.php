<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Stub;

use OxPHP\Runtime\Tests\Stub\FakeOxRequest;
use OxPHP\Runtime\Tests\Stub\OxPHPHarness;
use OxPHP\Server\Exception\InvalidServeContextException;
use OxPHP\Server\Worker;
use PHPUnit\Framework\TestCase;

final class WorkerPolyfillTest extends TestCase
{
    protected function setUp(): void
    {
        OxPHPHarness::reset();
    }

    public function test_current_returns_singleton(): void
    {
        self::assertSame(Worker::current(), Worker::current());
    }

    public function test_is_worker_mode_reflects_harness(): void
    {
        self::assertFalse(Worker::isWorkerMode());
        OxPHPHarness::instance()->setWorker(true);
        self::assertTrue(Worker::isWorkerMode());
    }

    public function test_serve_drives_handler_in_worker_mode(): void
    {
        OxPHPHarness::instance()->setWorker(true)->pushRequest(FakeOxRequest::get('/a'));
        $seen = 0;
        Worker::current()->serve(static function () use (&$seen): void {
            $seen++;
        });
        self::assertSame(1, $seen);
    }

    public function test_serve_throws_outside_worker_mode(): void
    {
        OxPHPHarness::instance()->setWorker(false);
        $this->expectException(InvalidServeContextException::class);
        Worker::current()->serve(static fn (): null => null);
    }
}
