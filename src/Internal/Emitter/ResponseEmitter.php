<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Emitter;

use OxPHP\Runtime\Internal\Bridge\OxPHP;

/**
 * @internal Writes HTTP headers once, then emits the body either as a
 * single string, as a stream of chunks (flushed to the wire via
 * oxphp_stream_flush() between chunks), or by driving a streaming
 * callback live (see emitCallback()) — enabling SSE and chunked transfer
 * without additional plumbing.
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
        $this->sendHeaders($status, $headers);

        if (\is_string($body)) {
            echo $body;
            return;
        }

        foreach ($body as $chunk) {
            if ($chunk === '') {
                continue;
            }
            echo $chunk;
            $this->bridge->streamFlush();
        }
    }

    /**
     * Drives a streaming callback (e.g. from a Symfony StreamedResponse) so
     * that every chunk it produces reaches the wire before the callback
     * continues — chunks are never collected and replayed after the fact.
     *
     * A chunk-size-1 pass-through output handler intercepts each echo: the
     * engine writes the returned buffer through to the SAPI itself (the only
     * output path PHP permits while a user output handler is running). The
     * handler must NOT call oxphp_stream_flush() — the OxPHP SAPI raises
     * "Cannot use output buffering in output buffering display handlers"
     * when it is invoked from handler context (verified on OxPHP 0.11.0).
     * Per-chunk wire pushes are instead delegated to ob_implicit_flush():
     * the engine invokes the SAPI flush hook right after each pass-through
     * write, which OxPHP wires to the stream push — verified live on 0.11.0
     * (chunks arrive as separate TCP reads, whether or not the callback
     * calls flush() itself). The single oxphp_stream_flush() below runs in
     * the finally block, in main context after the handler is closed, as
     * the final push for tail bytes.
     *
     * A callback that throws must not poison the worker: all output buffers
     * opened at or above ours are closed before the throwable is rethrown,
     * discarding partial data — bytes already streamed cannot be unsent.
     *
     * @param array<string, string|list<string>> $headers
     */
    public function emitCallback(int $status, array $headers, ?\Closure $callback): void
    {
        $this->sendHeaders($status, $headers);

        if ($callback === null) {
            return;
        }

        // The runtime implicit-flush flag has no getter; the INI value is
        // what the SAPI booted with (the CLI SAPI forces it to "1").
        $restoreImplicitFlush = \filter_var(\ini_get('implicit_flush'), \FILTER_VALIDATE_BOOL);
        $level = \ob_get_level();

        \ob_implicit_flush(true);
        \ob_start(static fn (string $buffer): string => $buffer, 1);

        try {
            $callback();
            $this->closeBuffersDownTo($level, flush: true);
        } catch (\Throwable $e) {
            $this->closeBuffersDownTo($level, flush: false);
            throw $e;
        } finally {
            \ob_implicit_flush($restoreImplicitFlush);
            $this->bridge->streamFlush();
        }
    }

    /** @param array<string, string|list<string>> $headers */
    private function sendHeaders(int $status, array $headers): void
    {
        if (\headers_sent()) {
            return;
        }

        \http_response_code($status);
        foreach ($headers as $name => $values) {
            foreach ((array) $values as $v) {
                \header($name . ': ' . $v, false);
            }
        }
    }

    /**
     * Closes our streaming buffer plus anything the callback left open above
     * it (flushing or discarding), so the worker's buffer stack stays
     * balanced across requests.
     */
    private function closeBuffersDownTo(int $level, bool $flush): void
    {
        while (\ob_get_level() > $level) {
            $flush ? \ob_end_flush() : \ob_end_clean();
        }
    }
}
