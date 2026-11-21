<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Runner;

use Nyholm\Psr7\Factory\Psr17Factory;
use OxPHP\Runtime\Internal\Bridge\OxPHP;
use OxPHP\Runtime\Internal\Emitter\Psr17FactoryLocator;
use OxPHP\Runtime\Internal\Runner\Psr7ResponseRunner;
use OxPHP\Runtime\Tests\Stub\FakeOxRequest;
use OxPHP\Runtime\Tests\Stub\MisreportedSizeStream;
use OxPHP\Runtime\Tests\Stub\OxPHPHarness;
use PHPUnit\Framework\TestCase;

final class Psr7ResponseRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        OxPHPHarness::reset();
        OxPHPHarness::instance()->setCurrentRequest(FakeOxRequest::get('/'));
    }

    protected function tearDown(): void
    {
        OxPHPHarness::reset();
    }

    public function test_emits_psr7_response_body_and_headers(): void
    {
        $factory = new Psr17Factory();
        $response = $factory
            ->createResponse(201)
            ->withHeader('X-Test', 'yes')
            ->withBody($factory->createStream('hello'));

        $runner = new Psr7ResponseRunner($response, new OxPHP(), new Psr17FactoryLocator());

        \ob_start();
        $code = $runner->run();
        $out = (string) \ob_get_clean();

        self::assertSame(0, $code);
        self::assertSame('hello', $out);
        // Known size: sent whole, so the server buffers it and may compress it.
        self::assertSame(0, OxPHPHarness::instance()->flushCount());
    }

    public function test_a_piped_body_past_the_limit_is_flushed_per_chunk_although_it_reports_size_0(): void
    {
        $body = \str_repeat('0123456789abcdef', 196608) . \str_repeat('L', 20000); // 3 MiB + 20000
        $response = new Psr17Factory()->createResponse()->withBody(MisreportedSizeStream::pipe($body));

        \ob_start();
        new Psr7ResponseRunner($response, new OxPHP(), new Psr17FactoryLocator())->run();
        $out = (string) \ob_get_clean();

        self::assertSame(\md5($body), \md5($out));
        self::assertSame(4, OxPHPHarness::instance()->flushCount());
    }
}
