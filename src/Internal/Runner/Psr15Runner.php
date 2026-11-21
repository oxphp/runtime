<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Runner;

use OxPHP\Http\RequestInterface as OxRequest;
use OxPHP\Runtime\Internal\Bridge\OxPHP;
use OxPHP\Runtime\Internal\Emitter\Psr17Factories;
use OxPHP\Runtime\Internal\Emitter\Psr17FactoryLocator;
use OxPHP\Runtime\Internal\Emitter\ResponseEmitter;
use OxPHP\Runtime\Internal\Reset\ResetterChain;
use Psr\Http\Message\ServerRequestInterface;
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
    private ?string $serverSoftware = null;

    public function __construct(
        private readonly RequestHandlerInterface $handler,
        OxPHP $bridge,
        private readonly Psr17FactoryLocator $locator,
        array $userResetters,
        array $bootServer = [],
    ) {
        parent::__construct($bridge, new ResetterChain($userResetters), $bootServer);
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
            Psr7Support::emittableBody($response->getBody()),
        );
    }

    private function buildPsr7Request(OxRequest $ox, Psr17Factories $f): ServerRequestInterface
    {
        $server = ServerParams::build($ox, $this->serverSoftware ??= $this->bridge->serverSoftware());
        $req = Psr7Support::baseRequest($ox, $f->serverRequest, $f->stream, $server)
            ->withUploadedFiles($this->convertFiles($_FILES ?? [], $f));

        $parsed = $ox->payload();
        if (\is_array($parsed) || \is_object($parsed)) {
            $req = $req->withParsedBody($parsed);
        }

        return $req;
    }

    /**
     * Builds the PSR-7 uploaded files from $_FILES, keeping the form's field
     * structure: `avatar`, `photos[]` and `user[avatar]` come out under
     * ['avatar'], ['photos'][0] and ['user']['avatar']. OxPHP builds $_FILES
     * even with SUPERGLOBALS_ENABLED=false; the request proxy's files() is a
     * flat list without the field names.
     *
     * @param array<array-key, mixed> $files
     * @return array<array-key, mixed>
     */
    private function convertFiles(array $files, Psr17Factories $f): array
    {
        $out = [];
        foreach ($files as $field => $spec) {
            if (\is_array($spec) && \array_key_exists('tmp_name', $spec)) {
                $out[$field] = $this->convertFileSpec(
                    $spec['tmp_name'],
                    $spec['size'] ?? null,
                    $spec['error'] ?? null,
                    $spec['name'] ?? null,
                    $spec['type'] ?? null,
                    $f,
                );
            }
        }
        return $out;
    }

    /**
     * One field of $_FILES. PHP files an array field as parallel trees, each
     * tmp_name under the same keys as its size, error, name and type, so this
     * walks tmp_name and takes the same key from the others.
     */
    private function convertFileSpec(mixed $tmpName, mixed $size, mixed $error, mixed $name, mixed $type, Psr17Factories $f): mixed
    {
        if (\is_array($tmpName)) {
            $out = [];
            foreach ($tmpName as $key => $sub) {
                $out[$key] = $this->convertFileSpec($sub, $size[$key] ?? null, $error[$key] ?? null, $name[$key] ?? null, $type[$key] ?? null, $f);
            }
            return $out;
        }

        $error = \is_int($error) ? $error : \UPLOAD_ERR_NO_FILE;
        // A file input left empty, or a failed upload, has no file to open.
        $stream = $error === \UPLOAD_ERR_OK && \is_string($tmpName)
            ? $f->stream->createStreamFromFile($tmpName, 'r')
            : $f->stream->createStream();

        return $f->uploadedFile->createUploadedFile(
            $stream,
            \is_int($size) ? $size : null,
            $error,
            \is_string($name) ? $name : null,
            \is_string($type) ? $type : null,
        );
    }
}
