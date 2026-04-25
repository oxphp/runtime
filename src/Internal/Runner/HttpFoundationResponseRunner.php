<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Runner;

use OxPHP\Runtime\Internal\Bridge\OxPHP;
use OxPHP\Runtime\Internal\Emitter\ResponseEmitter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Runtime\RunnerInterface;

/**
 * @internal Emits a pre-built HttpFoundation Response (or StreamedResponse).
 * One-shot — the runner is not meant for worker-loop use; returning a
 * Response literal from index.php is a degenerate case for trivial scripts.
 */
final class HttpFoundationResponseRunner implements RunnerInterface
{
    private readonly ResponseEmitter $emitter;
    private readonly OxPHP $bridge;

    public function __construct(
        private readonly Response $response,
        OxPHP $bridge,
    ) {
        $this->bridge = $bridge;
        $this->emitter = new ResponseEmitter($bridge);
    }

    public function run(): int
    {
        $headers = [];
        foreach ($this->response->headers->all() as $name => $values) {
            $headers[$name] = $values;
        }

        if ($this->response instanceof StreamedResponse) {
            // Drive the callback ourselves so we can flush between buffer
            // writes rather than relying on sendContent() calling flush().
            $this->emitter->emit(
                $this->response->getStatusCode(),
                $headers,
                $this->driveStreamedCallback($this->response),
            );
            return 0;
        }

        $this->emitter->emit(
            $this->response->getStatusCode(),
            $headers,
            (string) $this->response->getContent(),
        );
        return 0;
    }

    /** @return \Generator<string> */
    private function driveStreamedCallback(StreamedResponse $response): \Generator
    {
        $callback = (function (): ?\Closure {
            return $this->callback;
        })->call($response);
        if ($callback === null) {
            return;
        }

        // ob_start chunk handler: every flush from user code becomes a chunk.
        $chunks = [];
        \ob_start(static function (string $buffer) use (&$chunks): string {
            if ($buffer !== '') {
                $chunks[] = $buffer;
            }
            return '';
        }, 1);

        try {
            $callback();
        } finally {
            \ob_end_flush();
        }

        foreach ($chunks as $chunk) {
            yield $chunk;
        }
    }
}
