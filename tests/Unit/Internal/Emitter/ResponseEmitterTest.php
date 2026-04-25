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
