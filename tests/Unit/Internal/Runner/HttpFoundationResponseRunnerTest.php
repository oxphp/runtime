<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Runner;

use OxPHP\Runtime\Internal\Bridge\OxPHP;
use OxPHP\Runtime\Internal\Runner\HttpFoundationResponseRunner;
use OxPHP\Runtime\Tests\Stub\FakeOxRequest;
use OxPHP\Runtime\Tests\Stub\OxPHPHarness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class HttpFoundationResponseRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        OxPHPHarness::reset();
        OxPHPHarness::instance()->setCurrentRequest(FakeOxRequest::get('/'));
    }

    public function test_plain_response_emits_content(): void
    {
        $resp = new Response('plain body', 202, ['X-K' => 'v']);

        \ob_start();
        new HttpFoundationResponseRunner($resp, new OxPHP())->run();
        $out = (string) \ob_get_clean();

        self::assertSame('plain body', $out);
    }

    public function test_streamed_response_flushes_per_chunk(): void
    {
        $resp = new StreamedResponse(static function (): void {
            echo 'a';
            echo 'b';
        });

        \ob_start();
        new HttpFoundationResponseRunner($resp, new OxPHP())->run();
        $out = (string) \ob_get_clean();

        self::assertSame('ab', $out);
        self::assertGreaterThanOrEqual(1, OxPHPHarness::instance()->flushCount());
    }
}
