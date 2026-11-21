<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Integration\Symfony;

use OxPHP\Runtime\Internal\Fiber\RequestScopes;
use OxPHP\Runtime\Tests\Integration\Symfony\App\ResetCounter;
use OxPHP\Runtime\Tests\Integration\Symfony\App\TestKernel;
use OxPHP\Runtime\Tests\Stub\RequestFiber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Overlapping requests on a real FrameworkBundle + SecurityBundle kernel with `OxPHPBundle`.
 *
 * Each simulated worker request is a `RequestFiber` that runs `handle()`, then a
 * streamed response's callback (`sendContent()`, where `HttpKernelRunner` uses its
 * emitter), then `terminate()`, in the runner's order. Routes pause with `\Fiber::suspend()` when asked (`?suspend=1`), so a test can
 * let other requests run and finish while one is still in flight (issue #5).
 */
final class FiberIsolationKernelTest extends TestCase
{
    private string $varDir = '';

    private ?TestKernel $kernel = null;

    private string $intlLocale = '';

    /** @var array<mixed> */
    private array $server = [];

    /** @var array<mixed> */
    private array $env = [];

    private false|string $shellVerbosity = false;

    protected function setUp(): void
    {
        RequestScopes::resetForTests();

        // Put back in tearDown(): the locale switcher sets the process-wide ICU
        // default locale, a debug kernel's boot sets SHELL_VERBOSITY, and
        // FrameworkBundle's boot sets DOCTRINE_DEPRECATIONS.
        $this->intlLocale = \Locale::getDefault();
        $this->server = $_SERVER;
        $this->env = $_ENV;
        $this->shellVerbosity = \getenv('SHELL_VERBOSITY');

        $this->varDir = \sys_get_temp_dir() . '/oxphp-runtime-kernel-' . \bin2hex(\random_bytes(8));
        new Filesystem()->mkdir($this->varDir . '/log');
    }

    protected function tearDown(): void
    {
        if ($this->kernel !== null) {
            $container = $this->kernel->getContainer();
            $this->kernel->shutdown();
            if ($container instanceof ResetInterface) {
                $container->reset();
            }
            $this->kernel = null;
        }

        new Filesystem()->remove($this->varDir);

        \Locale::setDefault($this->intlLocale);
        $_SERVER = $this->server;
        $_ENV = $this->env;
        \putenv($this->shellVerbosity === false ? 'SHELL_VERBOSITY' : 'SHELL_VERBOSITY=' . $this->shellVerbosity);

        RequestScopes::resetForTests();
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function provideReal_bundles_get_isolation_installedCases(): iterable
    {
        yield 'debug' => [true];

        yield 'no debug' => [false];
    }

    #[DataProvider('provideReal_bundles_get_isolation_installedCases')]
    public function test_real_bundles_get_isolation_installed(bool $debug): void
    {
        $container = $this->bootKernel($debug)->getContainer();

        self::assertTrue($container->getParameter('oxphp_runtime.fiber_isolation'));
        self::assertSame('', $container->getParameter('oxphp_runtime.fiber_isolation_reason'));
    }

    public function test_main_request_is_each_requests_own_while_a_stream_is_open(): void
    {
        $this->bootKernel();

        $streams = [];
        for ($i = 0; $i < 4; ++$i) {
            $streams[] = $this->startRequest(Request::create('/stream?suspend=1'));
        }

        foreach ($streams as $stream) {
            self::assertFalse($stream->isTerminated(), 'Every stream stays open in its callback.');
        }

        $answers = [];
        for ($i = 0; $i < 40; ++$i) {
            $answers[] = self::content($this->startRequest(Request::create('/main')));
        }

        self::assertSame(\array_fill(0, 40, 'own'), $answers);

        foreach ($streams as $stream) {
            self::assertTrue($stream->resume()->isTerminated());
        }
    }

    public function test_locale_is_per_request(): void
    {
        $this->bootKernel();

        $a = $this->startRequest(Request::create('/en/hello?suspend=1'));
        $b = $this->startRequest(Request::create('/ru/hello?suspend=1'));

        // A resumes first, while B is still in flight: without the bundle A renders
        // in B's locale. In the other order stock Symfony passes too, because B
        // finishes first and its `kernel.finish_request` listeners restore the
        // locale of the request under it on the shared stack, which is A.
        $a->resume();

        self::assertSame('Hello|en|en', self::content($a));

        $b->resume();

        self::assertSame('Привет|ru|ru', self::content($b));
    }

    public function test_locale_survives_another_request_finishing(): void
    {
        $this->bootKernel();

        $a = $this->startRequest(Request::create('/en/hello?suspend=1'));
        $b = $this->startRequest(Request::create('/ru/hello'));

        self::assertSame('Привет|ru|ru', self::content($b));

        $a->resume();

        self::assertSame('Hello|en|en', self::content($a));
    }

    public function test_login_persists_when_another_request_finishes_first(): void
    {
        $this->bootKernel();

        $login = Request::create('/login?suspend=1');
        $a = $this->startRequest($login);
        $b = $this->startRequest(Request::create('/main'));

        self::assertTrue($b->isTerminated(), 'B ran to its end while A was in flight.');

        $a->resume();

        self::assertSame('logged in', self::content($a));
        self::assertTrue($login->getSession()->has('_security_main'), "A's token was saved to A's session.");
    }

    public function test_logout_path_survives_another_request_finishing(): void
    {
        $this->bootKernel();

        $a = $this->startRequest(Request::create('/logout-path?suspend=1'));
        $b = $this->startRequest(Request::create('/main'));

        self::assertTrue($b->isTerminated(), 'B ran to its end while A was in flight.');

        $a->resume();

        self::assertSame('/logout', self::content($a));
    }

    public function test_kernel_reset_waits_for_an_idle_worker(): void
    {
        // `ServicesResetter` skips services that were never instantiated.
        $counter = $this->bootKernel()->getContainer()->get(ResetCounter::class);
        self::assertInstanceOf(ResetCounter::class, $counter);

        $a = $this->startRequest(Request::create('/stream?suspend=1'));
        $b = $this->startRequest(Request::create('/main'));

        self::assertTrue($b->isTerminated(), 'B ran to its end while the stream was open.');
        self::assertSame(0, $counter->resets, 'No reset while the stream is open.');

        self::assertTrue($a->resume()->isTerminated());

        $c = $this->startRequest(Request::create('/main'));

        self::assertTrue($c->isTerminated());
        self::assertSame(1, $counter->resets, 'The next request on an idle worker resets.');
    }

    public function test_a_user_fiber_inside_a_worker_request_sees_no_request(): void
    {
        $this->bootKernel();

        self::assertSame('null', self::content($this->startRequest(Request::create('/user-fiber'))));
    }

    public function test_outside_worker_mode_everything_behaves_stock(): void
    {
        $this->bootKernel();

        self::assertSame('Hello|en|en', $this->handle(Request::create('/en/hello'))->getContent());
        self::assertSame('Привет|ru|ru', $this->handle(Request::create('/ru/hello'))->getContent());
        self::assertSame('own', $this->handle(Request::create('/main'))->getContent());
        self::assertSame('request', $this->handle(Request::create('/user-fiber'))->getContent());
        self::assertSame('/logout', $this->handle(Request::create('/logout-path'))->getContent());
    }

    private function bootKernel(bool $debug = true): TestKernel
    {
        $kernel = new TestKernel($this->varDir, $debug);
        $kernel->boot();

        // Only a booted kernel has a container for tearDown() to shut down.
        return $this->kernel = $kernel;
    }

    /**
     * One request as the worker runs it: `handle()`, the streamed body, `terminate()`.
     */
    private function handle(Request $request): Response
    {
        $kernel = $this->kernel;
        \assert($kernel !== null);

        $response = $kernel->handle($request);

        if ($response instanceof StreamedResponse) {
            $response->sendContent();
        }

        $kernel->terminate($request, $response);

        return $response;
    }

    /**
     * Starts the request in its own request fiber; it runs until it ends or suspends.
     */
    private function startRequest(Request $request): RequestFiber
    {
        return RequestFiber::start(fn (): Response => $this->handle($request));
    }

    private static function content(RequestFiber $request): string
    {
        self::assertTrue($request->isTerminated(), 'The request ran to its end.');

        $response = $request->result();
        self::assertInstanceOf(Response::class, $response);

        return (string) $response->getContent();
    }
}
