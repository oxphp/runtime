<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Runner;

use OxPHP\Http\RequestInterface as OxRequest;
use OxPHP\Http\UploadedFileInterface as OxUploadedFile;
use OxPHP\Runtime\Internal\Bridge\OxPHP;
use OxPHP\Runtime\Internal\Emitter\Psr17Factories;
use OxPHP\Runtime\Internal\Emitter\Psr17FactoryLocator;
use OxPHP\Runtime\Internal\Emitter\ResponseEmitter;
use OxPHP\Runtime\Internal\Reset\ResetterChain;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface as PsrUploadedFile;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @internal Runs a PSR-15 RequestHandler against every incoming OxPHP request.
 *
 * Builds a fresh PSR-7 ServerRequest per call via the configured PSR-17
 * factory. Reset chain is driven entirely by user-provided resetters —
 * PSR-15 handlers are stateless by contract.
 */
final class Psr15Runner extends AbstractHttpRunner
{
    private readonly ResponseEmitter $emitter;
    private ?Psr17Factories $factories = null;

    public function __construct(
        private readonly RequestHandlerInterface $handler,
        OxPHP $bridge,
        private readonly Psr17FactoryLocator $locator,
        array $userResetters,
    ) {
        parent::__construct($bridge, new ResetterChain($userResetters));
        $this->emitter = new ResponseEmitter($bridge);
    }

    protected function handleRequest(OxRequest $req): void
    {
        $factories = $this->factories ??= $this->locator->locate();

        $psr = $this->buildPsr7Request($req, $factories);
        $response = $this->handler->handle($psr);

        $this->emitter->emit(
            $response->getStatusCode(),
            $response->getHeaders(),
            $this->streamBody($response->getBody()),
        );
    }

    private function buildPsr7Request(OxRequest $ox, Psr17Factories $f): ServerRequestInterface
    {
        $req = $f->serverRequest
            ->createServerRequest($ox->method(), $ox->fullUri())
            ->withProtocolVersion($ox->httpProtocolVersion())
            ->withCookieParams($ox->cookies())
            ->withQueryParams($ox->query() ?? [])
            ->withUploadedFiles($this->convertFiles($ox->files(), $f))
            ->withBody($f->stream->createStream($ox->body()))
            ->withAttribute('OXPHP_REQUEST_ID', \oxphp_request_id());

        foreach ($ox->headers() as $name => $value) {
            $req = $req->withHeader($name, $value);
        }

        $parsed = $ox->payload();
        if (\is_array($parsed) || \is_object($parsed)) {
            $req = $req->withParsedBody($parsed);
        }

        return $req;
    }

    /**
     * @param list<OxUploadedFile> $oxFiles
     * @return array<string, PsrUploadedFile>
     */
    private function convertFiles(array $oxFiles, Psr17Factories $f): array
    {
        $out = [];
        foreach ($oxFiles as $of) {
            $stream = $f->stream->createStreamFromFile($of->tmpPath(), 'r');
            $out[$of->name()] = $f->uploadedFile->createUploadedFile(
                $stream,
                $of->size(),
                $of->error(),
                $of->name(),
                $of->clientType(),
            );
        }
        return $out;
    }

    /** @return \Generator<string> */
    private function streamBody(\Psr\Http\Message\StreamInterface $body): \Generator
    {
        if ($body->isSeekable()) {
            $body->rewind();
        }
        while (!$body->eof()) {
            yield $body->read(8192);
        }
    }
}
