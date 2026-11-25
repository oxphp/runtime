<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Emitter;

use OxPHP\Runtime\Internal\Bridge\OxPHP;

/**
 * @internal Writes HTTP headers once, then emits the body either as a
 * single string, as a stream of chunks (flushed to the wire via
 * oxphp_stream_flush() between chunks), or by driving a streaming
 * callback (see emitCallback()), whose output OxPHP sends as it is
 * written only in stream mode.
 */
final class ResponseEmitter
{
    /**
     * @param bool $replaceHeaders how the response's headers meet the ones PHP already
     *        queued (the app's header() calls, the session cache limiter). true, for PSR-7
     *        responses: each header's first value replaces what is queued under that name
     *        and the others are added after it; Set-Cookie never replaces, so a cookie the
     *        app set natively (the session's) stays. This is what Laminas' SapiEmitter and
     *        Slim's ResponseEmitter do. false, for HttpFoundation responses: every value is
     *        added, as Symfony's Response::sendHeaders() adds all but Content-Type.
     */
    public function __construct(
        private readonly OxPHP $bridge,
        private readonly bool $replaceHeaders = false,
    ) {}

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
     * that every chunk it produces reaches the SAPI before the callback
     * continues — chunks are never collected and replayed after the fact.
     *
     * A chunk-size-1 pass-through output handler intercepts each echo: the
     * engine writes the returned buffer through to the SAPI itself (the only
     * output path PHP permits while a user output handler is running). The
     * handler must NOT call oxphp_stream_flush() — the OxPHP SAPI raises
     * "Cannot use output buffering in output buffering display handlers"
     * when it is invoked from handler context (verified on OxPHP 0.11.0).
     * ob_implicit_flush() makes the engine call the SAPI flush hook right
     * after each pass-through write, whether or not the callback calls
     * flush() itself. OxPHP sends anything from that hook only in stream
     * mode (verified on 0.16.0). An SSE response is in stream mode from its
     * Content-Type header on, so its chunks go out as written. Any other
     * response enters it only at the first oxphp_stream_flush(), so the
     * server holds its output until the callback makes that call itself or
     * returns. The single oxphp_stream_flush() below runs once the callback
     * has returned, in main context after the handler is closed: it sends
     * the headers if nothing has yet, and whatever the server still holds.
     *
     * Streaming every response would take turning stream mode on before the
     * callback, and OxPHP can't do that without sending the headers:
     * oxphp_stream_flush() sends them at once, so a callback that throws
     * before writing could no longer get a 500, and its header() calls would
     * be lost (issue #17).
     *
     * A callback that throws must not poison the worker: all output buffers
     * opened at or above ours are closed before the throwable is rethrown,
     * discarding partial data — bytes already streamed cannot be unsent. It
     * gets no flush: OxPHP's first oxphp_stream_flush() sends the headers,
     * which would put this response's status on the wire before the runner's
     * fallback 500 could replace it. What it wrote before throwing is with
     * the SAPI already and goes out with the request.
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
        }

        $this->bridge->streamFlush();
    }

    /** @param array<string, string|list<string>> $headers */
    private function sendHeaders(int $status, array $headers): void
    {
        if (\headers_sent()) {
            return;
        }

        foreach ($headers as $name => $values) {
            $replace = $this->replaceHeaders && \strcasecmp((string) $name, 'Set-Cookie') !== 0;

            foreach ((array) $values as $v) {
                \header($name . ': ' . $v, $replace);
                $replace = false;
            }
        }

        // After the headers, as SapiEmitter does: PHP turns the status into 302 when a
        // Location header is sent while the status is neither 201 nor 3xx.
        \http_response_code($status);
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
