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
 * source for:
 * - the entry script's paths: `SCRIPT_NAME`, `SCRIPT_FILENAME`, `PHP_SELF`,
 *   `DOCUMENT_ROOT`, `DOCUMENT_URI`, and `PATH_INFO` (set for a URL that names
 *   the entry file, such as `/index.php/news`);
 * - `REMOTE_PORT` and `GATEWAY_INTERFACE`;
 * - `OXPHP_TRACE_ID`, `OXPHP_SPAN_ID` and `OXPHP_PARENT_SPAN_ID`, set when tracing
 *   is on, and `OXPHP_DENIED_PATH` and `OXPHP_DENIED_PATTERN`, set when a denied
 *   path falls back to another script;
 * - the process environment, which is read from `$_ENV` or `getenv()`.
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
            // OxPHP's name for a request that names no host: HTTP/1.0 may leave out `Host`.
            'SERVER_NAME' => $ox->host() !== '' ? $ox->host() : 'localhost',
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

            // `HTTP_HOST` among them, so only a request that has `Host` gets it, as sent.
            $server['HTTP_' . \strtoupper(\str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }
}
