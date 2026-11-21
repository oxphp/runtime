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
 * chunking the emitter consumes.
 */
final class Psr7Support
{
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
