<?php
declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Emitter;

use OxPHP\Runtime\Internal\Bridge\OxPHP;

/**
 * @internal Writes HTTP headers once, then emits the body either as a
 * single string or as a stream of chunks. Between chunks it calls
 * oxphp_stream_flush() so OxPHP writes the chunk to the wire immediately
 * — enabling SSE and chunked transfer without additional plumbing.
 */
final class ResponseEmitter
{
    public function __construct(private readonly OxPHP $bridge) {}

    /**
     * @param array<string, string|list<string>> $headers
     * @param iterable<string>|string $body
     */
    public function emit(int $status, array $headers, iterable|string $body): void
    {
        if (!\headers_sent()) {
            \http_response_code($status);
            foreach ($headers as $name => $values) {
                foreach ((array) $values as $v) {
                    \header($name.': '.$v, false);
                }
            }
        }

        if (\is_string($body)) {
            echo $body;
            return;
        }

        foreach ($body as $chunk) {
            if ($chunk === '') { continue; }
            echo $chunk;
            $this->bridge->streamFlush();
        }
    }
}
