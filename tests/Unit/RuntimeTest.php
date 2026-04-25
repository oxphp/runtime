<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit;

use Nyholm\Psr7\Factory\Psr17Factory;
use OxPHP\Runtime\Internal\Runner\HttpFoundationResponseRunner;
use OxPHP\Runtime\Internal\Runner\HttpKernelRunner;
use OxPHP\Runtime\Internal\Runner\LaravelRunner;
use OxPHP\Runtime\Internal\Runner\Psr15Runner;
use OxPHP\Runtime\Internal\Runner\Psr7ResponseRunner;
use OxPHP\Runtime\Runtime;
use OxPHP\Runtime\Tests\Stub\Apps\FakeLaravelKernel;
use OxPHP\Runtime\Tests\Stub\Apps\FakePsr15Handler;
use OxPHP\Runtime\Tests\Stub\Apps\FakeSymfonyKernel;
use OxPHP\Runtime\Tests\Stub\OxPHPHarness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class RuntimeTest extends TestCase
{
    /** Skip SymfonyRuntime's global error/exception handler registration; tests don't exercise it. */
    private const array NO_HANDLER = ['error_handler' => false];

    protected function setUp(): void
    {
        OxPHPHarness::reset();
    }

    public function test_dispatches_psr15_handler_to_psr15_runner(): void
    {
        $runtime = new Runtime(self::NO_HANDLER);
        $runner = $runtime->getRunner(FakePsr15Handler::echoingPath());
        self::assertInstanceOf(Psr15Runner::class, $runner);
    }

    public function test_dispatches_laravel_kernel_to_laravel_runner(): void
    {
        $runtime = new Runtime(self::NO_HANDLER);
        $runner = $runtime->getRunner(FakeLaravelKernel::echoingPath());
        self::assertInstanceOf(LaravelRunner::class, $runner);
    }

    public function test_dispatches_symfony_kernel_to_http_kernel_runner(): void
    {
        $runtime = new Runtime(self::NO_HANDLER);
        $runner = $runtime->getRunner(FakeSymfonyKernel::echoingPath());
        self::assertInstanceOf(HttpKernelRunner::class, $runner);
    }

    public function test_dispatches_http_foundation_response(): void
    {
        $runtime = new Runtime(self::NO_HANDLER);
        $runner = $runtime->getRunner(new Response('x'));
        self::assertInstanceOf(HttpFoundationResponseRunner::class, $runner);
    }

    public function test_dispatches_psr7_response(): void
    {
        $runtime = new Runtime(self::NO_HANDLER);
        $response = new Psr17Factory()->createResponse(204);
        $runner = $runtime->getRunner($response);
        self::assertInstanceOf(Psr7ResponseRunner::class, $runner);
    }

    public function test_unsupported_type_throws_logic_exception(): void
    {
        $runtime = new Runtime(self::NO_HANDLER);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('OxPHP Runtime does not support');

        $runtime->getRunner(new \stdClass());
    }

    public function test_resetters_option_is_forwarded_to_runners(): void
    {
        $called = 0;
        $runtime = new Runtime(self::NO_HANDLER + ['resetters' => [static function () use (&$called): void {
            $called++;
        }]]);

        $h = OxPHPHarness::instance();
        $h->setWorker(true);
        $h->pushRequest(\OxPHP\Runtime\Tests\Stub\FakeOxRequest::get('/'));

        $runner = $runtime->getRunner(FakePsr15Handler::echoingPath());
        $runner->run();

        self::assertSame(1, $called);
    }
}
