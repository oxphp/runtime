<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Runner;

use OxPHP\Runtime\Internal\Bridge\OxPHP;
use OxPHP\Runtime\Internal\Emitter\ResponseEmitter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @internal Emits an HttpFoundation Response through the ResponseEmitter —
 * the single place that maps Symfony response shapes onto emitter calls.
 *
 * StreamedResponse callbacks are driven live via emitCallback(): each chunk
 * the callback produces is flushed to the wire before the callback continues,
 * instead of being collected and replayed after it returns.
 */
final class HttpFoundationResponseEmitter
{
    private readonly ResponseEmitter $emitter;

    public function __construct(OxPHP $bridge)
    {
        $this->emitter = new ResponseEmitter($bridge);
    }

    public function emit(Response $response): void
    {
        if ($response instanceof StreamedResponse) {
            $this->emitter->emitCallback(
                $response->getStatusCode(),
                $response->headers->all(),
                $response->getCallback(),
            );
            return;
        }

        $this->emitter->emit(
            $response->getStatusCode(),
            $response->headers->all(),
            (string) $response->getContent(),
        );
    }
}
