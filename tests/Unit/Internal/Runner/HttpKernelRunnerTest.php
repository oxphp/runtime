<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Runner;

use OxPHP\Runtime\Internal\Bridge\OxPHP;
use OxPHP\Runtime\Internal\Runner\HttpKernelRunner;
use OxPHP\Runtime\Tests\Stub\Apps\FakeSymfonyKernel;
use OxPHP\Runtime\Tests\Stub\FakeOxRequest;
use OxPHP\Runtime\Tests\Stub\OxPHPHarness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function test_request_carries_server_software(): void
    {
        OxPHPHarness::instance()->setCurrentRequest(FakeOxRequest::get('/'));
        $kernel = FakeSymfonyKernel::echoingPath();

        \ob_start();
        new HttpKernelRunner($kernel, new OxPHP(), userResetters: [])->run();
        \ob_end_clean();

        self::assertSame('OxPHP/0.0.0-test', $kernel->handled[0]->server->get('SERVER_SOFTWARE'));
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

    public function test_streamed_response_interleaves_emission_with_callback(): void
    {
        OxPHPHarness::instance()->setCurrentRequest(FakeOxRequest::get('/sse'));

        $events = [];
        $kernel = new FakeSymfonyKernel(static function () use (&$events): Response {
            return new StreamedResponse(static function () use (&$events): void {
                echo 'one';
                $events[] = 'cb:one flushes=' . OxPHPHarness::instance()->flushCount();
                echo 'two';
                $events[] = 'cb:two flushes=' . OxPHPHarness::instance()->flushCount();
            });
        });

        \ob_start();
        // Observer below the runtime's handler: records each chunk the
        // instant the engine writes it through to the next output level.
        \ob_start(static function (string $b) use (&$events): string {
            if ($b !== '') {
                $events[] = 'wire:' . $b;
            }
            return $b;
        }, 1);
        new HttpKernelRunner($kernel, new OxPHP(), userResetters: [])->run();
        \ob_end_flush();
        $out = (string) \ob_get_clean();

        self::assertSame('onetwo', $out);
        self::assertSame(
            ['wire:one', 'cb:one flushes=0', 'wire:two', 'cb:two flushes=0'],
            $events,
        );
        self::assertSame(1, OxPHPHarness::instance()->flushCount());
        self::assertCount(1, $kernel->terminated);
    }

    public function test_worker_survives_mid_stream_exception(): void
    {
        $h = OxPHPHarness::instance();
        $h->setWorker(true);
        $h->pushRequest(FakeOxRequest::get('/boom'));
        $h->pushRequest(FakeOxRequest::get('/ok'));

        $level = \ob_get_level();
        $kernel = new FakeSymfonyKernel(static function (Request $r): Response {
            if ($r->getPathInfo() === '/boom') {
                return new StreamedResponse(static function (): void {
                    echo 'partial';
                    \ob_start(); // user-opened buffer left unclosed at throw time
                    echo 'trapped';
                    throw new \RuntimeException('stream died');
                });
            }
            return new Response('sym:' . $r->getPathInfo());
        });

        $resets = 0;
        new HttpKernelRunner($kernel, new OxPHP(), userResetters: [static function () use (&$resets): void {
            $resets++;
        }])->run();

        $outputs = $h->capturedOutputs();
        self::assertCount(2, $outputs);
        self::assertStringContainsString('partial', $outputs[0]);
        self::assertStringNotContainsString('trapped', $outputs[0]);
        self::assertStringContainsString('Internal Server Error', $outputs[0]);
        self::assertSame('sym:/ok', $outputs[1]);
        self::assertSame(2, $resets);
        self::assertSame($level, \ob_get_level());
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
