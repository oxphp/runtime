<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Yii;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * @internal What `Yiisoft\Yii\Runner\Http\HttpApplicationRunner` does to a response
 * right before emitting it, with its defaults: no body for the statuses that have
 * none, a `Content-Length` from the body size, and no body for a HEAD request,
 * keeping the length of the body a GET would get. A copy of its private
 * `removeBodyByStatusMiddleware()`, `contentLengthMiddleware()` and
 * `headRequestMiddleware()`, applied in that order.
 */
final class ResponsePreparer
{
    /** Continue, Switching Protocols, Processing, No Content, Reset Content, Not Modified. */
    private const array NO_BODY_STATUSES = [100, 101, 102, 204, 205, 304];

    public static function prepare(
        ServerRequestInterface $request,
        ResponseInterface $response,
        StreamFactoryInterface $streams,
    ): ResponseInterface {
        $response = self::removeBodyByStatus($response, $streams);
        $response = self::contentLength($response);

        return self::headRequest($request, $response, $streams);
    }

    private static function removeBodyByStatus(ResponseInterface $response, StreamFactoryInterface $streams): ResponseInterface
    {
        if (!\in_array($response->getStatusCode(), self::NO_BODY_STATUSES, true)) {
            return $response;
        }

        return $response->withBody($streams->createStream());
    }

    private static function contentLength(ResponseInterface $response): ResponseInterface
    {
        if ($response->hasHeader('Transfer-Encoding')) {
            return $response->withoutHeader('Content-Length');
        }

        if ($response->hasHeader('Content-Length')) {
            return $response;
        }

        if (\in_array($response->getStatusCode(), self::NO_BODY_STATUSES, true)) {
            return $response;
        }

        $body = $response->getBody();
        if (!$body->isReadable()) {
            return $response;
        }

        $contentLength = $body->getSize();
        if ($contentLength === null || $contentLength === 0) {
            return $response;
        }

        return $response->withHeader('Content-Length', (string) $contentLength);
    }

    private static function headRequest(
        ServerRequestInterface $request,
        ResponseInterface $response,
        StreamFactoryInterface $streams,
    ): ResponseInterface {
        if ($request->getMethod() !== 'HEAD') {
            return $response;
        }

        return $response->withBody($streams->createStream());
    }
}
