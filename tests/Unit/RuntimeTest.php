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
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Runtime\RunnerInterface;

final class RuntimeTest extends TestCase
{
    /** Skip SymfonyRuntime's global error/exception handler registration; tests don't exercise it. */
    private const array NO_HANDLER = ['error_handler' => false];

    /** @var array<string, mixed> */
    private array $serverBackup;
    /** @var array<string, mixed> */
    private array $envBackup;

    protected function setUp(): void
    {
        OxPHPHarness::reset();
        // SymfonyRuntime boots by writing into both superglobals.
        $this->serverBackup = $_SERVER;
        $this->envBackup = $_ENV;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_ENV = $this->envBackup;
    }

    public function test_dispatches_psr15_handler_to_psr15_runner(): void
    {
        $runtime = new Runtime(self::NO_HANDLER);
        $runner = $this->resolveRunner($runtime, FakePsr15Handler::echoingPath());
        self::assertInstanceOf(Psr15Runner::class, $runner);
    }

    #[RequiresMethod(\Illuminate\Contracts\Http\Kernel::class, 'handle')]
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

    public function test_worker_requests_see_the_server_vars_the_boot_wrote(): void
    {
        // autoload_runtime.php writes APP_RUNTIME just before constructing us.
        $_SERVER['APP_RUNTIME'] = Runtime::class;
        // The env option makes SymfonyRuntime's constructor write the env var
        // into $_SERVER, the same way its dotenv boot writes every .env variable.
        // (project_dir's APP_PROJECT_DIR would do too, but symfony/runtime 6.4
        // doesn't write it; every supported version writes the env var.)
        $runtime = new Runtime(self::NO_HANDLER + ['env' => 'boot-env', 'env_var_name' => 'OXPHP_TEST_BOOT_ENV']);
        // And the index.php callable runs after construction.
        $_SERVER['OXPHP_TEST_APP_VAR'] = 'from-callable';

        $h = OxPHPHarness::instance();
        $h->setWorker(true);
        $h->setRequestServer(['REQUEST_URI' => '/']);
        $h->pushRequest(FakeOxRequest::get('/'));
        $h->pushRequest(FakeOxRequest::get('/'));

        $seen = [];
        $app = new FakePsr15Handler(static function () use (&$seen): ResponseInterface {
            $seen[] = [
                $_SERVER['APP_RUNTIME'] ?? null,
                $_SERVER['OXPHP_TEST_BOOT_ENV'] ?? null,
                $_SERVER['OXPHP_TEST_APP_VAR'] ?? null,
            ];
            return new Psr17Factory()->createResponse(204);
        });
        $this->resolveRunner($runtime, $app)->run();

        $expected = [Runtime::class, 'boot-env', 'from-callable'];
        self::assertSame([$expected, $expected], $seen);
    }

    public function test_worker_requests_do_not_inherit_the_boot_request(): void
    {
        // The server runs the boot inside a synthetic request of its own; with
        // SUPERGLOBALS_ENABLED=false a real request's $_SERVER lacks these.
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_URI'] = '/';
        $runtime = new Runtime(self::NO_HANDLER);
        // Header-shaped keys always describe the request, whoever wrote them.
        $_SERVER['HTTP_X_FROM_BOOT'] = '1';

        $h = OxPHPHarness::instance();
        $h->setWorker(true);
        $h->setRequestServer([]);
        $h->pushRequest(FakeOxRequest::get('/'));

        $seen = null;
        $app = new FakePsr15Handler(static function () use (&$seen): ResponseInterface {
            $seen = \array_intersect_key($_SERVER, ['REMOTE_ADDR' => 0, 'REQUEST_URI' => 0, 'HTTP_X_FROM_BOOT' => 0]);
            return new Psr17Factory()->createResponse(204);
        });
        $this->resolveRunner($runtime, $app)->run();

        self::assertSame([], $seen);
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
