<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Runner;

use Nyholm\Psr7\Factory\Psr17Factory;
use OxPHP\Runtime\Internal\Runner\Psr7Support;
use OxPHP\Runtime\Tests\Stub\FakeOxRequest;
use OxPHP\Runtime\Tests\Stub\OxPHPHarness;
use PHPUnit\Framework\TestCase;

final class Psr7SupportTest extends TestCase
{
    protected function setUp(): void
    {
        OxPHPHarness::reset();
    }

    public function test_base_request_carries_method_uri_protocol_headers_cookies_query_body_and_server_params(): void
    {
        $f = new Psr17Factory();
        $ox = new FakeOxRequest(
            method: 'PUT',
            path: '/x',
            queryString: 'q=1',
            httpProtocolVersion: '2',
            headers: ['x-a' => '1'],
            cookies: ['c' => 'v'],
            query: ['q' => '1'],
            body: 'raw',
        );

        $r = Psr7Support::baseRequest($ox, $f, $f, ['REMOTE_ADDR' => '1.1.1.1']);

        self::assertSame('PUT', $r->getMethod());
        self::assertSame('http://localhost/x?q=1', (string) $r->getUri());
        self::assertSame('2', $r->getProtocolVersion());
        self::assertSame(['1'], $r->getHeader('x-a'));
        self::assertSame(['c' => 'v'], $r->getCookieParams());
        self::assertSame(['q' => '1'], $r->getQueryParams());
        self::assertSame('raw', (string) $r->getBody());
        self::assertSame('1.1.1.1', $r->getServerParams()['REMOTE_ADDR']);
        self::assertSame('test-req-id', $r->getAttribute('OXPHP_REQUEST_ID'));
    }

    public function test_body_chunks_reads_8192_bytes_at_a_time_from_the_start(): void
    {
        $stream = new Psr17Factory()->createStream(\str_repeat('x', 20_000));
        $stream->read(5); // a consumed stream is rewound
        $sizes = \array_map(\strlen(...), \iterator_to_array(Psr7Support::bodyChunks($stream), false));
        self::assertSame(20_000, \array_sum($sizes));
        self::assertSame(8192, $sizes[0]);
    }

    public function test_body_chunks_yields_nothing_for_a_body_that_cannot_be_read(): void
    {
        // As SapiEmitter: an unreadable body is not sent, rather than failing the response.
        $path = (string) \tempnam(\sys_get_temp_dir(), 'oxphp-runtime-test');
        $stream = new Psr17Factory()->createStreamFromFile($path, 'w');

        try {
            self::assertFalse($stream->isReadable());
            self::assertSame([], \iterator_to_array(Psr7Support::bodyChunks($stream), false));
        } finally {
            $stream->close();
            \unlink($path);
        }
    }
}
