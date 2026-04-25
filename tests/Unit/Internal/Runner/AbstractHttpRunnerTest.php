<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Runner;

use OxPHP\Http\RequestInterface as OxRequest;
use OxPHP\Runtime\Internal\Bridge\OxPHP;
use OxPHP\Runtime\Internal\Reset\ResetterChain;
use OxPHP\Runtime\Internal\Runner\AbstractHttpRunner;
use OxPHP\Runtime\Tests\Stub\FakeOxRequest;
use OxPHP\Runtime\Tests\Stub\OxPHPHarness;
use PHPUnit\Framework\TestCase;

final class AbstractHttpRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        OxPHPHarness::reset();
    }

    public function test_run_once_invokes_handle_request_and_returns_zero(): void
    {
        OxPHPHarness::instance()->setCurrentRequest(FakeOxRequest::get('/x'));

        $runner = $this->newRunner(static function (OxRequest $req, array &$state): void {
            $state[] = 'handle:' . $req->path();
            echo 'body';
        }, $state);

        \ob_start();
        $code = $runner->run();
        $out = (string) \ob_get_clean();

        self::assertSame(0, $code);
        self::assertSame('body', $out);
        self::assertSame(['handle:/x'], $state);
    }

    public function test_run_worker_iterates_requests_and_resets_between_each(): void
    {
        $h = OxPHPHarness::instance();
        $h->setWorker(true);
        $h->pushRequest(FakeOxRequest::get('/a'));
        $h->pushRequest(FakeOxRequest::get('/b'));

        $resetCalls = 0;
        $runner = $this->newRunner(
            handler: static function (OxRequest $req, array &$state): void {
                $state[] = 'h:' . $req->path();
                echo $req->path();
            },
            state: $state,
            resetters: [static function () use (&$resetCalls): void {
                $resetCalls++;
            }],
        );

        $code = $runner->run();

        self::assertSame(0, $code);
        self::assertSame(['h:/a', 'h:/b'], $state);
        self::assertSame(['/a', '/b'], $h->capturedOutputs());
        self::assertSame(2, $resetCalls);
    }

    public function test_worker_exception_emits_fallback_500_and_resets_still_runs(): void
    {
        $h = OxPHPHarness::instance();
        $h->setWorker(true);
        $h->pushRequest(FakeOxRequest::get('/boom'));

        $resets = 0;
        $runner = $this->newRunner(
            handler: static function (): void {
                throw new \RuntimeException('kaboom');
            },
            state: $state,
            resetters: [static function () use (&$resets): void {
                $resets++;
            }],
        );

        $runner->run();

        $out = $h->capturedOutputs()[0] ?? '';
        self::assertStringContainsString('Internal Server Error', $out);
        self::assertSame(1, $resets);
    }

    /**
     * @param callable(OxRequest, array): void $handler
     * @param list<callable> $resetters
     */
    private function newRunner(callable $handler, ?array &$state, array $resetters = []): AbstractHttpRunner
    {
        $state = [];
        $bridge = new OxPHP();
        $chain = new ResetterChain($resetters);

        return new class ($bridge, $chain, $handler, $state) extends AbstractHttpRunner {
            public function __construct(OxPHP $b, ResetterChain $c, private $handler, private array &$stateRef)
            {
                parent::__construct($b, $c);
            }

            protected function handleRequest(OxRequest $req): void
            {
                ($this->handler)($req, $this->stateRef);
            }
        };
    }
}
