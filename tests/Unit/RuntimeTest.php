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
use OxPHP\Runtime\Tests\Stub\FakeOxRequest;
use OxPHP\Runtime\Tests\Stub\OxPHPHarness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Runtime\RunnerInterface;

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
        $runner = $this->resolveRunner($runtime, FakePsr15Handler::echoingPath());
        self::assertInstanceOf(Psr15Runner::class, $runner);
    }

    public function test_dispatches_laravel_kernel_to_laravel_runner(): void
    {
        $runtime = new Runtime(self::NO_HANDLER);
        $runner = $this->resolveRunner($runtime, FakeLaravelKernel::echoingPath());
        self::assertInstanceOf(LaravelRunner::class, $runner);
    }

    public function test_dispatches_symfony_kernel_to_http_kernel_runner(): void
    {
        $runtime = new Runtime(self::NO_HANDLER);
        $runner = $this->resolveRunner($runtime, FakeSymfonyKernel::echoingPath());
        self::assertInstanceOf(HttpKernelRunner::class, $runner);
    }

    public function test_dispatches_http_foundation_response(): void
    {
        $runtime = new Runtime(self::NO_HANDLER);
        $runner = $this->resolveRunner($runtime, new Response('x'));
        self::assertInstanceOf(HttpFoundationResponseRunner::class, $runner);
    }

    public function test_dispatches_psr7_response(): void
    {
        $runtime = new Runtime(self::NO_HANDLER);
        $response = new Psr17Factory()->createResponse(204);
        $runner = $this->resolveRunner($runtime, $response);
        self::assertInstanceOf(Psr7ResponseRunner::class, $runner);
    }

    public function test_unsupported_type_throws_logic_exception(): void
    {
        $runtime = new Runtime(self::NO_HANDLER);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('OxPHP Runtime does not support');

        $this->resolveRunner($runtime, new \stdClass());
    }

    public function test_cli_sapi_falls_back_to_parent_runner(): void
    {
        // The suite runs under the CLI SAPI, so getRunner() must delegate to the
        // stock Symfony runtime (its dispatch, its message) rather than the
        // OxPHP dispatch table.
        $runtime = new Runtime(self::NO_HANDLER);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("doesn't know how to handle");

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
        $h->pushRequest(FakeOxRequest::get('/'));

        $runner = $this->resolveRunner($runtime, FakePsr15Handler::echoingPath());
        $runner->run();

        self::assertSame(1, $called);
    }

    /**
     * Invoke Runtime::resolveRunner() directly, bypassing the SAPI gate in
     * getRunner() so the dispatch table can be exercised under the CLI SAPI.
     */
    private function resolveRunner(Runtime $runtime, ?object $application): RunnerInterface
    {
        return new \ReflectionMethod(Runtime::class, 'resolveRunner')->invoke($runtime, $application);
    }
}
