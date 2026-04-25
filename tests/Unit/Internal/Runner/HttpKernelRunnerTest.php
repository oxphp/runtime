<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Runner;

use OxPHP\Runtime\Internal\Bridge\OxPHP;
use OxPHP\Runtime\Internal\Runner\HttpKernelRunner;
use OxPHP\Runtime\Tests\Stub\Apps\FakeSymfonyKernel;
use OxPHP\Runtime\Tests\Stub\FakeOxRequest;
use OxPHP\Runtime\Tests\Stub\OxPHPHarness;
use PHPUnit\Framework\TestCase;

final class HttpKernelRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        OxPHPHarness::reset();
    }

    public function test_synthesises_server_vars_and_emits_response(): void
    {
        OxPHPHarness::instance()->setCurrentRequest(FakeOxRequest::get('/hello'));

        $kernel = FakeSymfonyKernel::echoingPath();
        $runner = new HttpKernelRunner($kernel, new OxPHP(), userResetters: []);

        \ob_start();
        $runner->run();
        $out = (string) \ob_get_clean();

        self::assertSame('sym:/hello', $out);
        self::assertCount(1, $kernel->handled);
        self::assertSame('GET', $kernel->handled[0]->getMethod());
        self::assertSame('/hello', $kernel->handled[0]->getPathInfo());
    }

    public function test_terminate_called_after_emit_on_terminable_kernel(): void
    {
        OxPHPHarness::instance()->setCurrentRequest(FakeOxRequest::get('/'));
        $kernel = FakeSymfonyKernel::echoingPath();

        \ob_start();
        new HttpKernelRunner($kernel, new OxPHP(), userResetters: [])->run();
        \ob_end_clean();

        self::assertCount(1, $kernel->terminated);
    }

    public function test_worker_loop_resets_per_request(): void
    {
        $h = OxPHPHarness::instance();
        $h->setWorker(true);
        $h->pushRequest(FakeOxRequest::get('/a'));
        $h->pushRequest(FakeOxRequest::get('/b'));

        $resets = 0;
        $runner = new HttpKernelRunner(
            FakeSymfonyKernel::echoingPath(),
            new OxPHP(),
            userResetters: [static function () use (&$resets): void {
                $resets++;
            }],
        );

        $runner->run();

        self::assertSame(['sym:/a', 'sym:/b'], $h->capturedOutputs());
        self::assertSame(2, $resets);
    }
}
