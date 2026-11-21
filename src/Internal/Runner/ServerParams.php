<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Runner;

use OxPHP\Http\RequestInterface as OxRequest;

/**
 * @internal Builds a CGI-style `$_SERVER` array from an OxPHP request proxy
 * without reading the $_SERVER superglobal — OxPHP may be configured to leave
 * superglobals empty.
 */
final class ServerParams
{
    /** @return array<string, int|float|string> */
    public static function build(OxRequest $ox, string $serverSoftware): array
    {
        $qs = $ox->queryString();
        $uri = $ox->path() . ($qs !== null ? '?' . $qs : '');

        $server = [
            'REQUEST_METHOD' => $ox->method(),
            'REQUEST_URI' => $uri,
            'QUERY_STRING' => $qs ?? '',
            'SERVER_PROTOCOL' => $ox->httpProtocol(),
            'SERVER_SOFTWARE' => $serverSoftware,
            'HTTP_HOST' => self::host($ox),
            'SERVER_NAME' => $ox->host(),
            'SERVER_PORT' => (string) $ox->port(),
            'REMOTE_ADDR' => $ox->ip(),
            'HTTPS' => $ox->isSecure() ? 'on' : 'off',
            'REQUEST_TIME' => (int) $ox->startTime(),
            'REQUEST_TIME_FLOAT' => $ox->startTime(true),
            'REQUEST_SCHEME' => $ox->scheme(),
        ];

        foreach ($ox->headers() as $name => $value) {
            $server['HTTP_' . \strtoupper(\str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }

    private static function host(OxRequest $ox): string
    {
        $port = $ox->port();
        $isDefault = ($ox->scheme() === 'http' && $port === 80)
                  || ($ox->scheme() === 'https' && $port === 443);
        return $ox->host() . ($isDefault ? '' : ':' . $port);
    }
}
