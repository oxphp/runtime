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
        $server = ServerParams::build($ox, $this->serverSoftware);

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
}
