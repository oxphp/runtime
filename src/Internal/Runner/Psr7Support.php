<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Runner;

use OxPHP\Http\RequestInterface as OxRequest;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * @internal PSR-7 plumbing shared by the runners that speak PSR-7: the
 * request basics every OxPHP-to-PSR-7 conversion needs, and the response body
 * the emitter consumes.
 */
final class Psr7Support
{
    /**
     * The largest body OxPHP compresses: `MAX_COMPRESS_SIZE` in the server's
     * `src/server/compression.rs`.
     */
    private const int MAX_WHOLE_BODY_BYTES = 3 * 1024 * 1024;

    private const int CHUNK_BYTES = 8192;

    /**
     * Builds a PSR-7 server request carrying everything except uploaded files
     * and the parsed body, which callers add in their own way.
     *
     * @param array<string, mixed> $serverParams
     */
    public static function baseRequest(
        OxRequest $ox,
        ServerRequestFactoryInterface $requests,
        StreamFactoryInterface $streams,
        array $serverParams = [],
    ): ServerRequestInterface {
        $req = $requests
            ->createServerRequest($ox->method(), self::uri($ox), $serverParams)
            ->withProtocolVersion($ox->httpProtocolVersion())
            ->withCookieParams($ox->cookies())
            ->withQueryParams($ox->query() ?? [])
            ->withBody($streams->createStream($ox->body()))
            ->withAttribute('OXPHP_REQUEST_ID', \oxphp_request_id());

        foreach ($ox->headers() as $name => $value) {
            $req = $req->withHeader($name, $value);
        }

        return $req;
    }

    /**
     * OxPHP's full URI, with the host `SERVER_NAME` has when the request names
     * none: OxPHP's `http:///path` has no host, and PSR-7 factories refuse it.
     */
    private static function uri(OxRequest $ox): string
    {
        $uri = $ox->fullUri();
        if ($ox->host() !== '') {
            return $uri;
        }

        // OxPHP writes `scheme://host[:port]path[?query]`, so the host goes after `://`.
        $prefix = $ox->scheme() . '://';

        return $prefix . ServerParams::hostName($ox) . \substr($uri, \strlen($prefix));
    }

    /**
     * The body as `ResponseEmitter::emit()` takes it. OxPHP compresses only a body it
     * buffers whole, and `oxphp_stream_flush()` makes it stream the body instead. So a
     * body that ends within what the server compresses is one string, which goes out
     * without a flush; a longer body, or one of unknown size, is chunks, each flushed.
     * An unreadable body is nothing, as `SapiEmitter` sends none.
     *
     * A reported size is trusted only when it is over the limit. One within it can be
     * wrong (a pipe or a socket reports 0), so the body is read in 8 KiB steps up to one
     * byte past the limit: if it ends first, that is the string; if not, what was read
     * goes out as the first chunk and the rest follows from there. Either way at most
     * the limit plus one chunk is held. An unknown size is streamed from the start, so
     * a body produced over time (server-sent events) is not held back.
     *
     * Read before the emitter sends the headers: a body that fails to read within the
     * limit still gets a clean 500.
     *
     * @return iterable<string>|string
     */
    public static function emittableBody(StreamInterface $body): iterable|string
    {
        if (!$body->isReadable()) {
            return '';
        }

        $size = $body->getSize();
        if ($size === null || $size > self::MAX_WHOLE_BODY_BYTES) {
            return self::bodyChunks($body);
        }

        if ($body->isSeekable()) {
            $body->rewind();
        }

        $head = '';
        while (\strlen($head) <= self::MAX_WHOLE_BODY_BYTES && !$body->eof()) {
            $head .= $body->read(\min(self::CHUNK_BYTES, self::MAX_WHOLE_BODY_BYTES + 1 - \strlen($head)));
        }

        // Short of the limit plus one, the loop stopped at the end of the body.
        if (\strlen($head) <= self::MAX_WHOLE_BODY_BYTES) {
            return $head;
        }

        return self::chunksAfter($head, $body);
    }

    /**
     * Yields nothing for a body that can't be read, as `SapiEmitter` sends none.
     *
     * @return \Generator<string>
     */
    public static function bodyChunks(StreamInterface $body): \Generator
    {
        if (!$body->isReadable()) {
            return;
        }
        if ($body->isSeekable()) {
            $body->rewind();
        }

        yield from self::remainingChunks($body);
    }

    /**
     * $head, then the rest of $body from where reading it stopped, never rewound.
     *
     * @return \Generator<string>
     */
    private static function chunksAfter(string $head, StreamInterface $body): \Generator
    {
        yield $head;
        yield from self::remainingChunks($body);
    }

    /**
     * @return \Generator<string>
     */
    private static function remainingChunks(StreamInterface $body): \Generator
    {
        while (!$body->eof()) {
            yield $body->read(self::CHUNK_BYTES);
        }
    }
}
