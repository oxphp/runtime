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
            ->createServerRequest($ox->method(), $ox->fullUri(), $serverParams)
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
     * The body as `ResponseEmitter::emit()` takes it. OxPHP compresses only a body it
     * buffers whole, and `oxphp_stream_flush()` makes it stream the body instead. So a
     * body of known size, up to what the server compresses, is read into one string,
     * which goes out without a flush; a larger body, or one of unknown size, is chunks,
     * each flushed. An unreadable body is nothing, as `SapiEmitter` sends none.
     *
     * Read before the emitter sends the headers: a body that fails to read here still
     * gets a clean 500.
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

        return $body->getContents();
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
        while (!$body->eof()) {
            yield $body->read(8192);
        }
    }
}
