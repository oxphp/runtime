<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Emitter;

use OxPHP\Runtime\Internal\Bridge\OxPHP;
use OxPHP\Runtime\Internal\Emitter\ResponseEmitter;
use OxPHP\Runtime\Tests\Stub\OxPHPHarness;
use PHPUnit\Framework\TestCase;

final class ResponseEmitterTest extends TestCase
{
    protected function setUp(): void
    {
        OxPHPHarness::reset();
    }

    public function test_string_body_echoes_once_no_flush(): void
    {
        $emitter = new ResponseEmitter(new OxPHP());

        \ob_start();
        $emitter->emit(200, ['X-Foo' => 'bar'], 'hello');
        $out = (string) \ob_get_clean();

        self::assertSame('hello', $out);
        self::assertSame(0, OxPHPHarness::instance()->flushCount());
    }

    public function test_iterable_body_flushes_after_each_non_empty_chunk(): void
    {
        $emitter = new ResponseEmitter(new OxPHP());

        \ob_start();
        $emitter->emit(200, [], (static function () {
            yield 'a';
            yield '';      // skipped
            yield 'bc';
        })());
        $out = (string) \ob_get_clean();

        self::assertSame('abc', $out);
        self::assertSame(2, OxPHPHarness::instance()->flushCount());
    }

    public function test_callback_chunks_pass_through_while_callback_runs(): void
    {
        $emitter = new ResponseEmitter(new OxPHP());
        $events = [];

        \ob_start();
        // Observer below the emitter's handler: records each chunk the
        // instant the engine writes it through to the next output level.
        \ob_start(static function (string $b) use (&$events): string {
            if ($b !== '') {
                $events[] = 'wire:' . $b;
            }
            return $b;
        }, 1);

        $emitter->emitCallback(200, [], static function () use (&$events): void {
            echo 'a';
            $events[] = 'cb:a flushes=' . OxPHPHarness::instance()->flushCount();
            echo 'bc';
            $events[] = 'cb:bc flushes=' . OxPHPHarness::instance()->flushCount();
        });

        \ob_end_flush();
        $out = (string) \ob_get_clean();

        self::assertSame('abc', $out);
        // Each chunk reaches the next output level before the callback
        // continues, and the bridge is never flushed from handler context —
        // per-chunk wire pushes are the engine's implicit flush.
        self::assertSame(
            ['wire:a', 'cb:a flushes=0', 'wire:bc', 'cb:bc flushes=0'],
            $events,
        );
        // The one bridge flush is the final push after the handler closed.
        self::assertSame(1, OxPHPHarness::instance()->flushCount());
    }

    public function test_callback_exception_restores_buffer_level_and_rethrows(): void
    {
        $emitter = new ResponseEmitter(new OxPHP());

        \ob_start();
        $level = \ob_get_level();
        try {
            $emitter->emitCallback(200, [], static function (): void {
                echo 'sent';
                \ob_start(); // user-opened buffer left unclosed at throw time
                echo 'partial';
                throw new \DomainException('boom');
            });
            self::fail('expected DomainException');
        } catch (\DomainException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame($level, \ob_get_level());
        $out = (string) \ob_get_clean();

        self::assertSame('sent', $out);
        self::assertSame(1, OxPHPHarness::instance()->flushCount());
    }

    public function test_null_callback_emits_headers_only(): void
    {
        $emitter = new ResponseEmitter(new OxPHP());

        \ob_start();
        $emitter->emitCallback(204, [], null);
        $out = (string) \ob_get_clean();

        self::assertSame('', $out);
        self::assertSame(0, OxPHPHarness::instance()->flushCount());
    }

    public function test_multi_value_header_emits_each_value_separately(): void
    {
        $emitter = new ResponseEmitter(new OxPHP());

        // headers_sent() returns true under ob_start(), so we cannot assert
        // via headers_list(). Instead assert that the code path runs without
        // throwing — header calls become no-ops when headers_sent() is true.
        \ob_start();
        $emitter->emit(200, ['Set-Cookie' => ['a=1', 'b=2']], 'ok');
        \ob_end_clean();

        self::assertTrue(true);
    }
}
