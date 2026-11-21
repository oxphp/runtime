<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Runner;

use OxPHP\Http\RequestInterface as OxRequest;
use Symfony\Component\HttpFoundation\Request;

/**
 * @internal Builds a Symfony HttpFoundation Request from an OxPHP request
 * proxy without relying on the $_SERVER superglobal — OxPHP may be
 * configured to leave superglobals empty.
 *
 * Uploaded files are the exception: they come from $_FILES, which OxPHP
 * builds even with SUPERGLOBALS_ENABLED=false. The request proxy's files()
 * is a flat list without the form's field names.
 */
final class HttpFoundationRequestBuilder
{
    public function __construct(
        private readonly string $serverSoftware,
    ) {}

    public function build(OxRequest $ox): Request
    {
        $server = $this->buildServer($ox);

        return new Request(
            query:      $ox->query() ?? [],
            request:    \is_array($ox->payload()) ? $ox->payload() : [],
            attributes: [],
            cookies:    $ox->cookies(),
            files:      $_FILES ?? [],
            server:     $server,
            content:    $ox->body(),
        );
    }

    /** @return array<string, mixed> */
    private function buildServer(OxRequest $ox): array
    {
        $qs = $ox->queryString();
        $uri = $ox->path() . ($qs !== null ? '?' . $qs : '');

        $server = [
            'REQUEST_METHOD' => $ox->method(),
            'REQUEST_URI' => $uri,
            'QUERY_STRING' => $qs ?? '',
            'SERVER_PROTOCOL' => $ox->httpProtocol(),
            'SERVER_SOFTWARE' => $this->serverSoftware,
            'HTTP_HOST' => $this->host($ox),
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

    private function host(OxRequest $ox): string
    {
        $port = $ox->port();
        $isDefault = ($ox->scheme() === 'http' && $port === 80)
                  || ($ox->scheme() === 'https' && $port === 443);
        return $ox->host() . ($isDefault ? '' : ':' . $port);
    }
}
