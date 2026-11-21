<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub;

/**
 * Requests sent with curl to a real OxPHP server (nightly f10d3548, worker mode,
 * SUPERGLOBALS_ENABLED=true), each with the `$_SERVER` the server registered for it,
 * less the process environment and argc/argv. Each is replayed as a
 * {@see FakeOxRequest} returning what the request object returned for it.
 */
final class CapturedOxPHPServer
{
    public const string SOFTWARE = 'OxPHP/0.0.0-nightly.202611210737+f10d3548';

    /**
     * The keys the server sets that the OxPHP request API has no source for: the
     * entry script's paths, the client's port and the CGI version.
     */
    public const array NOT_FROM_THE_API = [
        'DOCUMENT_ROOT',
        'DOCUMENT_URI',
        'GATEWAY_INTERFACE',
        'PHP_SELF',
        'REMOTE_PORT',
        'SCRIPT_FILENAME',
        'SCRIPT_NAME',
    ];

    /**
     * `curl -H 'Accept: text/html' -H 'X-Custom: yes' -H 'X_Under: u' -H 'X.Dot: d'
     * -H 'Cookie: s=x' 'http://127.0.0.1:18080/server?a=1&b=two'`. The server leaves
     * the names with `_` or `.` out of `$_SERVER`.
     *
     * @return array{FakeOxRequest, array<string, int|float|string>}
     */
    public static function get(): array
    {
        return [
            new FakeOxRequest(
                path: '/server',
                queryString: 'a=1&b=two',
                host: '127.0.0.1',
                port: 18080,
                headers: [
                    'host' => '127.0.0.1:18080',
                    'user-agent' => 'curl/8.7.1',
                    'accept' => 'text/html',
                    'x-custom' => 'yes',
                    'x_under' => 'u',
                    'x.dot' => 'd',
                    'cookie' => 's=x',
                ],
                cookies: ['s' => 'x'],
                query: ['a' => '1', 'b' => 'two'],
                ip: '192.168.65.1',
                startTime: 1795297436.9514291,
            ),
            [
                'DOCUMENT_ROOT' => '/var/www/html/public',
                'DOCUMENT_URI' => '/index.php',
                'GATEWAY_INTERFACE' => 'CGI/1.1',
                'HTTP_ACCEPT' => 'text/html',
                'HTTP_COOKIE' => 's=x',
                'HTTP_HOST' => '127.0.0.1:18080',
                'HTTP_USER_AGENT' => 'curl/8.7.1',
                'HTTP_X_CUSTOM' => 'yes',
                'PHP_SELF' => '/index.php',
                'QUERY_STRING' => 'a=1&b=two',
                'REMOTE_ADDR' => '192.168.65.1',
                'REMOTE_PORT' => '48184',
                'REQUEST_METHOD' => 'GET',
                'REQUEST_SCHEME' => 'http',
                'REQUEST_TIME' => 1795297436,
                'REQUEST_TIME_FLOAT' => 1795297436.9514291,
                'REQUEST_URI' => '/server?a=1&b=two',
                'SCRIPT_FILENAME' => '/var/www/html/public/index.php',
                'SCRIPT_NAME' => '/index.php',
                'SERVER_NAME' => '127.0.0.1',
                'SERVER_PORT' => '18080',
                'SERVER_PROTOCOL' => 'HTTP/1.1',
                'SERVER_SOFTWARE' => self::SOFTWARE,
            ],
        ];
    }

    /**
     * `curl -H 'X-Custom: yes' --data-urlencode 'f=1' 'http://127.0.0.1:18080/server?q=post'`.
     *
     * @return array{FakeOxRequest, array<string, int|float|string>}
     */
    public static function post(): array
    {
        return [
            new FakeOxRequest(
                method: 'POST',
                path: '/server',
                queryString: 'q=post',
                host: '127.0.0.1',
                port: 18080,
                headers: [
                    'host' => '127.0.0.1:18080',
                    'user-agent' => 'curl/8.7.1',
                    'accept' => '*/*',
                    'x-custom' => 'yes',
                    'content-length' => '3',
                    'content-type' => 'application/x-www-form-urlencoded',
                ],
                query: ['q' => 'post'],
                payload: ['f' => '1'],
                body: 'f=1',
                ip: '192.168.65.1',
                startTime: 1795297437.2202425,
            ),
            [
                'CONTENT_LENGTH' => '3',
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
                'DOCUMENT_ROOT' => '/var/www/html/public',
                'DOCUMENT_URI' => '/index.php',
                'GATEWAY_INTERFACE' => 'CGI/1.1',
                'HTTP_ACCEPT' => '*/*',
                'HTTP_HOST' => '127.0.0.1:18080',
                'HTTP_USER_AGENT' => 'curl/8.7.1',
                'HTTP_X_CUSTOM' => 'yes',
                'PHP_SELF' => '/index.php',
                'QUERY_STRING' => 'q=post',
                'REMOTE_ADDR' => '192.168.65.1',
                'REMOTE_PORT' => '58289',
                'REQUEST_METHOD' => 'POST',
                'REQUEST_SCHEME' => 'http',
                'REQUEST_TIME' => 1795297437,
                'REQUEST_TIME_FLOAT' => 1795297437.2202425,
                'REQUEST_URI' => '/server?q=post',
                'SCRIPT_FILENAME' => '/var/www/html/public/index.php',
                'SCRIPT_NAME' => '/index.php',
                'SERVER_NAME' => '127.0.0.1',
                'SERVER_PORT' => '18080',
                'SERVER_PROTOCOL' => 'HTTP/1.1',
                'SERVER_SOFTWARE' => self::SOFTWARE,
            ],
        ];
    }

    /**
     * `curl -H 'Host: example.test:8443' 'http://127.0.0.1:18080/server/x/y'`: no query,
     * and a host and port that come from the `Host` header.
     *
     * @return array{FakeOxRequest, array<string, int|float|string>}
     */
    public static function hostWithPort(): array
    {
        return [
            new FakeOxRequest(
                path: '/server/x/y',
                host: 'example.test',
                port: 8443,
                headers: [
                    'host' => 'example.test:8443',
                    'user-agent' => 'curl/8.7.1',
                    'accept' => '*/*',
                ],
                ip: '192.168.65.1',
                startTime: 1795297437.3338463,
            ),
            [
                'DOCUMENT_ROOT' => '/var/www/html/public',
                'DOCUMENT_URI' => '/index.php',
                'GATEWAY_INTERFACE' => 'CGI/1.1',
                'HTTP_ACCEPT' => '*/*',
                'HTTP_HOST' => 'example.test:8443',
                'HTTP_USER_AGENT' => 'curl/8.7.1',
                'PHP_SELF' => '/index.php',
                'QUERY_STRING' => '',
                'REMOTE_ADDR' => '192.168.65.1',
                'REMOTE_PORT' => '49108',
                'REQUEST_METHOD' => 'GET',
                'REQUEST_SCHEME' => 'http',
                'REQUEST_TIME' => 1795297437,
                'REQUEST_TIME_FLOAT' => 1795297437.3338463,
                'REQUEST_URI' => '/server/x/y',
                'SCRIPT_FILENAME' => '/var/www/html/public/index.php',
                'SCRIPT_NAME' => '/index.php',
                'SERVER_NAME' => 'example.test',
                'SERVER_PORT' => '8443',
                'SERVER_PROTOCOL' => 'HTTP/1.1',
                'SERVER_SOFTWARE' => self::SOFTWARE,
            ],
        ];
    }

    /**
     * The captured POST with another method, path, content type and body; the keys
     * that depend on them change as the server sets them.
     *
     * @return array{FakeOxRequest, array<string, int|float|string>}
     */
    public static function postWith(string $method, string $path, string $contentType, string $body, mixed $payload): array
    {
        [, $server] = self::post();
        $length = (string) \strlen($body);

        return [
            new FakeOxRequest(
                method: $method,
                path: $path,
                host: '127.0.0.1',
                port: 18080,
                headers: [
                    'host' => '127.0.0.1:18080',
                    'user-agent' => 'curl/8.7.1',
                    'accept' => '*/*',
                    'x-custom' => 'yes',
                    'content-length' => $length,
                    'content-type' => $contentType,
                ],
                payload: $payload,
                body: $body,
                ip: '192.168.65.1',
                startTime: 1795297437.2202425,
            ),
            [
                'CONTENT_LENGTH' => $length,
                'CONTENT_TYPE' => $contentType,
                'QUERY_STRING' => '',
                'REQUEST_METHOD' => $method,
                'REQUEST_URI' => $path,
            ] + $server,
        ];
    }
}
