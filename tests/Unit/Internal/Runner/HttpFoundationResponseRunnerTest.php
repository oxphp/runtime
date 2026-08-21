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

    public function test_streamed_response_emits_body_and_final_flush(): void
    {
        $resp = new StreamedResponse(static function (): void {
            echo 'a';
            echo 'b';
        });

        \ob_start();
        new HttpFoundationResponseRunner($resp, new OxPHP())->run();
        $out = (string) \ob_get_clean();

        self::assertSame('ab', $out);
        self::assertSame(1, OxPHPHarness::instance()->flushCount());
    }

    public function test_streamed_response_built_from_chunks_streams_each_chunk(): void
    {
        // Symfony's setChunks() callback calls @ob_flush() and flush() after
        // each echo — both must be harmless against the streaming handler.
        $resp = new StreamedResponse((static function (): \Generator {
            yield 'x';
            yield 'y';
        })());

        \ob_start();
        new HttpFoundationResponseRunner($resp, new OxPHP())->run();
        $out = (string) \ob_get_clean();

        self::assertSame('xy', $out);
        self::assertSame(1, OxPHPHarness::instance()->flushCount());
    }

    public function test_streamed_chunks_pass_through_while_callback_runs(): void
    {
        $events = [];
        $resp = new StreamedResponse(static function () use (&$events): void {
            echo 'a';
            $events[] = 'cb:a flushes=' . OxPHPHarness::instance()->flushCount();
            echo 'b';
            $events[] = 'cb:b flushes=' . OxPHPHarness::instance()->flushCount();
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
        new HttpFoundationResponseRunner($resp, new OxPHP())->run();
        \ob_end_flush();
        $out = (string) \ob_get_clean();

        self::assertSame('ab', $out);
        // Each chunk goes out before the callback produces the next one —
        // not collected and replayed after the callback returns — and the
        // bridge is never flushed from handler context.
        self::assertSame(
            ['wire:a', 'cb:a flushes=0', 'wire:b', 'cb:b flushes=0'],
            $events,
        );
        self::assertSame(1, OxPHPHarness::instance()->flushCount());
    }
}
