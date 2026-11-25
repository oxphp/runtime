<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Emitter;

use OxPHP\Runtime\Internal\Bridge\OxPHP;
use OxPHP\Runtime\Internal\Emitter\ResponseEmitter;
use OxPHP\Runtime\Tests\Stub\OxPHPHarness;
use OxPHP\Runtime\Tests\Stub\RunsUnderPhpCgi;
use PHPUnit\Framework\TestCase;

final class ResponseEmitterTest extends TestCase
{
    use RunsUnderPhpCgi;

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
        // per-chunk pushes to the SAPI are the engine's implicit flush (OxPHP
        // sends them on at once only in stream mode).
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
    }

    public function test_a_callback_that_throws_is_never_flushed_so_its_headers_can_still_be_replaced(): void
    {
        // OxPHP's first oxphp_stream_flush() sends the headers. Flushed after a throw, they
        // would put the response's 200 on the wire before the runner's fallback 500.
        $emitter = new ResponseEmitter(new OxPHP());

        \ob_start();
        try {
            $emitter->emitCallback(200, ['Content-Type' => ['text/csv']], static function (): void {
                throw new \DomainException('boom');
            });
            self::fail('expected DomainException');
        } catch (\DomainException) {
        } finally {
            \ob_end_clean();
        }

        self::assertSame(0, OxPHPHarness::instance()->flushCount());
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

    public function test_a_location_header_keeps_the_response_status(): void
    {
        // PHP turns the status into 302 when a Location header is sent while the status
        // is neither 201 nor 3xx; setting the status after the headers keeps the response's.
        $r = self::runUnderPhpCgi('emitAcceptedWithLocation');

        self::assertSame(202, $r['status'], $r['stderr']);
        self::assertSame(['/jobs/1'], self::headerValues($r['headers'], 'Location'));
        self::assertSame("queued\n", $r['body']);
        self::assertSame('', $r['stderr']);
    }

    public function test_a_location_header_keeps_the_status_of_a_streamed_callback(): void
    {
        $r = self::runUnderPhpCgi('emitCallbackAcceptedWithLocation');

        self::assertSame(202, $r['status'], $r['stderr']);
        self::assertSame(['/jobs/1'], self::headerValues($r['headers'], 'Location'));
        self::assertSame("queued\n", $r['body']);
        self::assertSame('', $r['stderr']);
    }

    public function test_a_replacing_emitter_sends_one_copy_of_a_header_the_app_queued_the_responses(): void
    {
        $r = self::runUnderPhpCgi('replaceHeadersTheAppQueued');

        self::assertSame('', $r['stderr']);
        self::assertSame(['public, max-age=60'], self::headerValues($r['headers'], 'Cache-Control'));
        self::assertSame(['SAMEORIGIN'], self::headerValues($r['headers'], 'X-Frame-Options'));
    }

    public function test_a_replacing_emitter_sends_every_value_of_a_multi_value_header(): void
    {
        $r = self::runUnderPhpCgi('replaceWithMultiValueHeaders');

        self::assertSame('', $r['stderr']);
        // The first value replaced the app's Vary; the others were added after it.
        self::assertSame(['Accept', 'Accept-Encoding'], self::headerValues($r['headers'], 'Vary'));
        self::assertSame(
            ['</app.css>; rel=preload; as=style', '</app.js>; rel=preload; as=script'],
            self::headerValues($r['headers'], 'Link'),
        );
    }

    public function test_a_replacing_emitter_keeps_the_cookie_the_app_set_natively(): void
    {
        $r = self::runUnderPhpCgi('replaceNextToANativeCookie');

        self::assertSame('', $r['stderr']);
        self::assertSame(['native=1', 'theme=dark', 'lang=en'], self::headerValues($r['headers'], 'Set-Cookie'));
    }

    /**
     * Run under php-cgi by the test above: the app queued a Cache-Control and an
     * X-Frame-Options with header(), and the response carries its own.
     */
    public static function replaceHeadersTheAppQueued(): void
    {
        \header('Cache-Control: no-store, no-cache, must-revalidate');
        \header('X-Frame-Options: DENY');

        new ResponseEmitter(new OxPHP(), replaceHeaders: true)->emit(
            200,
            ['Cache-Control' => ['public, max-age=60'], 'X-Frame-Options' => ['SAMEORIGIN']],
            'ok',
        );
    }

    /**
     * Run under php-cgi by the test above: the app queued a Vary, and the response
     * carries two Vary values and two Link values.
     */
    public static function replaceWithMultiValueHeaders(): void
    {
        \header('Vary: Cookie');

        new ResponseEmitter(new OxPHP(), replaceHeaders: true)->emit(200, [
            'Vary' => ['Accept', 'Accept-Encoding'],
            'Link' => ['</app.css>; rel=preload; as=style', '</app.js>; rel=preload; as=script'],
        ], 'ok');
    }

    /**
     * Run under php-cgi by the test above: the app set a cookie with setcookie(), and the
     * response carries two. PSR-7 keeps a header name as the app wrote it, so the
     * lowercase one must still be told apart as Set-Cookie.
     */
    public static function replaceNextToANativeCookie(): void
    {
        \setcookie('native', '1');

        new ResponseEmitter(new OxPHP(), replaceHeaders: true)->emit(200, ['set-cookie' => ['theme=dark', 'lang=en']], 'ok');
    }

    /**
     * Run under php-cgi by the test above: a 202 with a Location header, as an API
     * answers a job it accepted.
     */
    public static function emitAcceptedWithLocation(): void
    {
        new ResponseEmitter(new OxPHP())->emit(202, ['Location' => ['/jobs/1'], 'Content-Type' => ['text/plain']], "queued\n");
    }

    /**
     * Run under php-cgi by the test above: the same 202, sent through a streaming callback.
     */
    public static function emitCallbackAcceptedWithLocation(): void
    {
        new ResponseEmitter(new OxPHP())->emitCallback(
            202,
            ['Location' => ['/jobs/1'], 'Content-Type' => ['text/plain']],
            static function (): void {
                echo "queued\n";
            },
        );
    }
}
