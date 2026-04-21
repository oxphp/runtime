<?php
declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Runner;

use OxPHP\Runtime\Internal\Bridge\OxPHP;
use OxPHP\Runtime\Internal\Runner\LaravelRunner;
use OxPHP\Runtime\Tests\Stub\Apps\FakeLaravelKernel;
use OxPHP\Runtime\Tests\Stub\FakeOxRequest;
use OxPHP\Runtime\Tests\Stub\OxPHPHarness;
use PHPUnit\Framework\TestCase;

final class LaravelRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        OxPHPHarness::reset();
    }

    public function test_handle_and_terminate_invoked_in_order(): void
    {
        OxPHPHarness::instance()->setCurrentRequest(FakeOxRequest::get('/users/1'));

        $kernel = FakeLaravelKernel::echoingPath();
        $runner = new LaravelRunner($kernel, new OxPHP(), userResetters: []);

        \ob_start();
        $runner->run();
        $out = (string) \ob_get_clean();

        self::assertSame('lar:/users/1', $out);
        self::assertCount(1, $kernel->handled);
        self::assertSame(1, $kernel->terminated);
    }

    public function test_worker_loop_runs_terminate_per_request_and_resets(): void
    {
        $h = OxPHPHarness::instance();
        $h->setWorker(true);
        $h->pushRequest(FakeOxRequest::get('/a'));
        $h->pushRequest(FakeOxRequest::get('/b'));

        $resets = 0;
        $kernel = FakeLaravelKernel::echoingPath();
        $runner = new LaravelRunner(
            $kernel, new OxPHP(),
            userResetters: [static function () use (&$resets): void { $resets++; }],
        );

        $runner->run();

        self::assertSame(['lar:/a', 'lar:/b'], $h->capturedOutputs());
        self::assertSame(2, $kernel->terminated);
        self::assertSame(2, $resets);
    }
}
