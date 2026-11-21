<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Runner;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Stream;
use OxPHP\Runtime\Internal\Runner\Psr7Support;
use OxPHP\Runtime\Internal\Runner\ServerParams;
use OxPHP\Runtime\Tests\Stub\CapturedOxPHPServer;
use OxPHP\Runtime\Tests\Stub\FakeOxRequest;
use OxPHP\Runtime\Tests\Stub\MisreportedSizeStream;
use OxPHP\Runtime\Tests\Stub\OxPHPHarness;
use OxPHP\Runtime\Tests\Stub\UnknownSizeStream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Psr7SupportTest extends TestCase
{
    private const int LIMIT = 3 * 1024 * 1024;

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

    /**
     * @return iterable<string, array{FakeOxRequest, string}>
     */
    public static function provideA_request_without_a_host_gets_localhost_as_the_uri_host_with_the_port_server_params_haveCases(): iterable
    {
        yield 'HTTP/1.0 without Host (captured)' => [CapturedOxPHPServer::http10WithoutHost()[0], 'http://localhost/server.php?a=1'];
        // A trusted proxy's X-Forwarded-Port still sets the port.
        yield 'a forwarded port' => [new FakeOxRequest(path: '/p', host: '', port: 8080, httpProtocolVersion: '1.0'), 'http://localhost:8080/p'];
        yield 'https' => [new FakeOxRequest(path: '/', scheme: 'https', host: '', port: 443, httpProtocolVersion: '1.0'), 'https://localhost/'];
    }

    #[DataProvider('provideA_request_without_a_host_gets_localhost_as_the_uri_host_with_the_port_server_params_haveCases')]
    public function test_a_request_without_a_host_gets_localhost_as_the_uri_host_with_the_port_server_params_have(FakeOxRequest $ox, string $expected): void
    {
        $f = new Psr17Factory();
        $server = ServerParams::build($ox, 'OxPHP/9.9.9');

        $uri = Psr7Support::baseRequest($ox, $f, $f, $server)->getUri();

        self::assertSame($expected, (string) $uri);
        self::assertSame($server['SERVER_NAME'], $uri->getHost());
        self::assertSame((int) $server['SERVER_PORT'], $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80));
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

    public function test_a_body_of_known_size_up_to_the_compression_limit_is_emitted_as_one_string_from_the_start(): void
    {
        $stream = new Psr17Factory()->createStream(\str_repeat('x', 3 * 1024 * 1024 - 1) . 'y');
        $stream->read(5); // a consumed stream is rewound

        $body = Psr7Support::emittableBody($stream);

        self::assertIsString($body);
        self::assertSame(3 * 1024 * 1024, \strlen($body));
        self::assertStringEndsWith('xy', $body);
    }

    public function test_a_body_over_the_compression_limit_or_of_unknown_size_is_emitted_as_chunks(): void
    {
        $f = new Psr17Factory();

        foreach ([$f->createStream(\str_repeat('x', 3 * 1024 * 1024 + 1)), new UnknownSizeStream($f->createStream('abc'))] as $stream) {
            $body = Psr7Support::emittableBody($stream);

            self::assertInstanceOf(\Generator::class, $body);
            self::assertSame((string) $stream, \implode('', \iterator_to_array($body, false)));
        }
    }

    /**
     * @return iterable<string, array{\Closure(string): MisreportedSizeStream, int}>
     */
    public static function provideA_body_whose_size_is_wrong_or_unknown_is_read_in_bounded_steps_and_streamed_once_it_passes_the_limitCases(): iterable
    {
        $pipe = MisreportedSizeStream::pipe(...);
        $seekable = static fn (?int $size): \Closure => static function (string $contents) use ($size): MisreportedSizeStream {
            $stream = new MisreportedSizeStream(new Psr17Factory()->createStream($contents), $size, true);
            $stream->read(5); // a consumed stream is rewound

            return $stream;
        };

        yield 'pipe (size 0, not seekable), over the limit' => [$pipe, self::LIMIT + 20_000];
        yield 'pipe, one byte over the limit' => [$pipe, self::LIMIT + 1];
        yield 'seekable, reports size 0, over the limit' => [$seekable(0), self::LIMIT + 20_000];
        yield 'seekable, reports a small size, over the limit' => [$seekable(100), self::LIMIT + 20_000];
        yield 'seekable, unknown size, over the limit' => [$seekable(null), self::LIMIT + 20_000];
    }

    /**
     * @param \Closure(string): MisreportedSizeStream $make
     */
    #[DataProvider('provideA_body_whose_size_is_wrong_or_unknown_is_read_in_bounded_steps_and_streamed_once_it_passes_the_limitCases')]
    public function test_a_body_whose_size_is_wrong_or_unknown_is_read_in_bounded_steps_and_streamed_once_it_passes_the_limit(\Closure $make, int $bytes): void
    {
        $contents = self::numbered($bytes);
        $stream = $make($contents);

        $body = Psr7Support::emittableBody($stream);

        self::assertInstanceOf(\Generator::class, $body);
        $chunks = \iterator_to_array($body, false);
        // Every byte once, in order: nothing read ahead is lost, and no rewind repeats it.
        self::assertSame(\md5($contents), \md5(\implode('', $chunks)));
        self::assertLessThanOrEqual(self::LIMIT + 1, \max(\array_map(\strlen(...), $chunks)));
        self::assertLessThanOrEqual(8192, \max($stream->reads));
        self::assertFalse($stream->readWhole);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideA_piped_body_up_to_the_limit_is_read_in_bounded_steps_and_emitted_as_one_stringCases(): iterable
    {
        yield 'empty' => [0];
        yield 'small' => [20_000];
        yield 'exactly the limit' => [self::LIMIT];
    }

    #[DataProvider('provideA_piped_body_up_to_the_limit_is_read_in_bounded_steps_and_emitted_as_one_stringCases')]
    public function test_a_piped_body_up_to_the_limit_is_read_in_bounded_steps_and_emitted_as_one_string(int $bytes): void
    {
        $contents = self::numbered($bytes);
        $stream = MisreportedSizeStream::pipe($contents);

        $body = Psr7Support::emittableBody($stream);

        self::assertIsString($body);
        self::assertSame(\md5($contents), \md5($body));
        self::assertLessThanOrEqual(8192, \max([0, ...$stream->reads]));
        self::assertFalse($stream->readWhole);
    }

    public function test_a_real_pipe_reports_size_0_and_is_still_streamed_once_it_passes_the_limit(): void
    {
        $over = self::LIMIT + 20_000;
        $small = self::popen(100);
        $large = self::popen($over);

        try {
            // What makes a pipe a trap: Nyholm caches the size fstat() gives it.
            self::assertSame(0, $large->getSize());
            self::assertFalse($large->isSeekable());

            self::assertSame(self::numbered(100), Psr7Support::emittableBody($small));
            $body = Psr7Support::emittableBody($large);
            self::assertInstanceOf(\Generator::class, $body);
            self::assertSame(\md5(self::numbered($over)), \md5(\implode('', \iterator_to_array($body, false))));
        } finally {
            $small->close();
            $large->close();
        }
    }

    public function test_a_body_that_cannot_be_read_is_emitted_as_nothing(): void
    {
        $path = (string) \tempnam(\sys_get_temp_dir(), 'oxphp-runtime-test');
        $stream = new Psr17Factory()->createStreamFromFile($path, 'w');

        try {
            self::assertSame('', Psr7Support::emittableBody($stream));
        } finally {
            $stream->close();
            \unlink($path);
        }
    }

    /**
     * $bytes of numbered 8-byte lines, so a repeated or a dropped piece changes it.
     */
    private static function numbered(int $bytes): string
    {
        $out = '';
        for ($i = 0; \strlen($out) < $bytes; ++$i) {
            $out .= \sprintf("%07d\n", $i);
        }

        return \substr($out, 0, $bytes);
    }

    /**
     * A stream on the output of a child process that prints self::numbered($bytes).
     */
    private static function popen(int $bytes): Stream
    {
        $code = \sprintf('$o = ""; for ($i = 0; strlen($o) < %1$d; ++$i) { $o .= sprintf("%%07d\\n", $i); } echo substr($o, 0, %1$d);', $bytes);
        $pipe = \popen(\escapeshellarg(\PHP_BINARY) . ' -r ' . \escapeshellarg($code), 'r');
        self::assertIsResource($pipe);

        return Stream::create($pipe);
    }
}
