<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Runner;

use OxPHP\Runtime\Internal\Bridge\OxPHP;
use OxPHP\Runtime\Internal\Emitter\Psr17FactoryLocator;
use OxPHP\Runtime\Internal\Runner\Psr15Runner;
use OxPHP\Runtime\Tests\Stub\Apps\FakePsr15Handler;
use OxPHP\Runtime\Tests\Stub\FakeOxRequest;
use OxPHP\Runtime\Tests\Stub\OxPHPHarness;
use PHPUnit\Framework\TestCase;

final class Psr15RunnerTest extends TestCase
{
    protected function setUp(): void
    {
        OxPHPHarness::reset();
    }

    public function test_one_shot_builds_psr7_request_from_oxphp_request_and_emits_response(): void
    {
        $harness = OxPHPHarness::instance();
        $harness->setCurrentRequest(FakeOxRequest::get('/hello', ['q' => 'v']));

        $handler = FakePsr15Handler::echoingPath();
        $runner = new Psr15Runner($handler, new OxPHP(), new Psr17FactoryLocator(), userResetters: []);

        \ob_start();
        $code = $runner->run();
        $out = (string) \ob_get_clean();

        self::assertSame(0, $code);
        self::assertSame('psr15:/hello', $out);
        self::assertCount(1, $handler->received);
        self::assertSame('GET', $handler->received[0]->getMethod());
        self::assertSame(['v'], $handler->received[0]->getQueryParams()['q'] ? ['v'] : ['']);
    }

    public function test_worker_loop_handles_multiple_requests_and_resets_between(): void
    {
        $h = OxPHPHarness::instance();
        $h->setWorker(true);
        $h->pushRequest(FakeOxRequest::get('/a'));
        $h->pushRequest(FakeOxRequest::get('/b'));

        $resets = 0;
        $handler = FakePsr15Handler::echoingPath();
        $runner = new Psr15Runner(
            $handler,
            new OxPHP(),
            new Psr17FactoryLocator(),
            userResetters: [static function () use (&$resets): void {
                $resets++;
            }],
        );

        $runner->run();

        self::assertSame(['psr15:/a', 'psr15:/b'], $h->capturedOutputs());
        self::assertSame(2, $resets);
    }

    public function test_parsed_body_passed_when_form_post(): void
    {
        OxPHPHarness::instance()->setCurrentRequest(
            FakeOxRequest::postForm('/submit', ['a' => '1', 'b' => '2'])
        );

        $captured = null;
        $handler = new FakePsr15Handler(static function ($req) use (&$captured) {
            $captured = $req->getParsedBody();
            $f = new \Nyholm\Psr7\Factory\Psr17Factory();
            return $f->createResponse(204);
        });

        \ob_start();
        new Psr15Runner($handler, new OxPHP(), new Psr17FactoryLocator(), userResetters: [])->run();
        \ob_end_clean();

        self::assertSame(['a' => '1', 'b' => '2'], $captured);
    }
}
