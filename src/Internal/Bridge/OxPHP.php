<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Bridge;

use OxPHP\Http\RequestInterface as OxRequest;

/**
 * @internal Thin wrapper over the oxphp extension functions.
 */
final class OxPHP
{
    public function isWorker(): bool
    {
        return \oxphp_is_worker();
    }
    public function isStreaming(): bool
    {
        return \oxphp_is_streaming();
    }
    public function streamFlush(): void
    {
        \oxphp_stream_flush();
    }
    public function finishRequest(): bool
    {
        return \oxphp_finish_request();
    }

    public function currentRequest(): OxRequest
    {
        return \oxphp_http_request();
    }
}
