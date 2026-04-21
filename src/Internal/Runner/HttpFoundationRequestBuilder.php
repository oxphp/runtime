<?php
declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Runner;

use OxPHP\Http\RequestInterface as OxRequest;
use OxPHP\Http\UploadedFileInterface as OxUploadedFile;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * @internal Builds a Symfony HttpFoundation Request from an OxPHP request
 * proxy without relying on the $_SERVER superglobal — OxPHP may be
 * configured to leave superglobals empty.
 */
final class HttpFoundationRequestBuilder
{
    public function build(OxRequest $ox): Request
    {
        $server = $this->buildServer($ox);

        return new Request(
            query:      $ox->query() ?? [],
            request:    \is_array($ox->payload()) ? $ox->payload() : [],
            attributes: [],
            cookies:    $ox->cookies(),
            files:      $this->convertFiles($ox->files()),
            server:     $server,
            content:    $ox->body(),
        );
    }

    /** @return array<string, mixed> */
    private function buildServer(OxRequest $ox): array
    {
        $qs = $ox->queryString();
        $uri = $ox->path().($qs !== null ? '?'.$qs : '');

        $server = [
            'REQUEST_METHOD'     => $ox->method(),
            'REQUEST_URI'        => $uri,
            'QUERY_STRING'       => $qs ?? '',
            'SERVER_PROTOCOL'    => $ox->httpProtocol(),
            'HTTP_HOST'          => $this->host($ox),
            'SERVER_NAME'        => $ox->host(),
            'SERVER_PORT'        => (string) $ox->port(),
            'REMOTE_ADDR'        => $ox->ip(),
            'HTTPS'              => $ox->isSecure() ? 'on' : 'off',
            'REQUEST_TIME'       => (int) $ox->startTime(),
            'REQUEST_TIME_FLOAT' => $ox->startTime(true),
            'REQUEST_SCHEME'     => $ox->scheme(),
        ];

        foreach ($ox->headers() as $name => $value) {
            $server['HTTP_'.\strtoupper(\str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }

    private function host(OxRequest $ox): string
    {
        $port = $ox->port();
        $isDefault = ($ox->scheme() === 'http' && $port === 80)
                  || ($ox->scheme() === 'https' && $port === 443);
        return $ox->host().($isDefault ? '' : ':'.$port);
    }

    /**
     * @param list<OxUploadedFile> $oxFiles
     * @return array<string, UploadedFile>
     */
    private function convertFiles(array $oxFiles): array
    {
        $out = [];
        foreach ($oxFiles as $of) {
            $out[$of->name()] = new UploadedFile(
                path: $of->tmpPath(),
                originalName: $of->name(),
                mimeType: $of->clientType(),
                error: $of->error(),
                test: true, // trust OxPHP's move semantics; no is_uploaded_file() check
            );
        }
        return $out;
    }
}
