<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Runner;

use OxPHP\Http\RequestInterface as OxRequest;

/**
 * @internal Builds a CGI-style `$_SERVER` array from an OxPHP request proxy
 * without reading the $_SERVER superglobal — OxPHP may be configured to leave
 * superglobals empty.
 *
 * It follows the `$_SERVER` OxPHP registers, less what the request API has no
 * source for: the entry script's paths (`SCRIPT_NAME`, `SCRIPT_FILENAME`,
 * `PHP_SELF`, `DOCUMENT_ROOT`, `DOCUMENT_URI`), `REMOTE_PORT` and
 * `GATEWAY_INTERFACE`.
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
            'REQUEST_TIME' => (int) $ox->startTime(),
            'REQUEST_TIME_FLOAT' => $ox->startTime(true),
            'REQUEST_SCHEME' => $ox->scheme(),
        ];

        // As OxPHP does: set only for a secure request, never "off".
        if ($ox->isSecure()) {
            $server['HTTPS'] = 'on';
        }

        foreach ($ox->headers() as $name => $value) {
            $name = \strtolower($name);

            // CGI names these two without the HTTP_ prefix.
            if ($name === 'content-type' || $name === 'content-length') {
                $server[\strtoupper(\str_replace('-', '_', $name))] = $value;

                continue;
            }

            // `X_Forwarded_For` and `X.Forwarded.For` would land on the key of the
            // `X-Forwarded-For` a proxy in front may have set, so OxPHP leaves such
            // names out of $_SERVER, as nginx does. The request's headers keep them.
            if (\strpbrk($name, '_.') !== false) {
                continue;
            }

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
