<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub\Yii;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * A response whose body is built on the first `getBody()`, as yii-view-renderer's
 * `ViewResponse` renders its view: a failure while building it comes after
 * `Application::handle()` has returned, while the runner prepares the response.
 */
final class DeferredBodyResponse implements ResponseInterface
{
    private ?StreamInterface $body = null;

    /** @param \Closure(): StreamInterface $build */
    public function __construct(private ResponseInterface $response, private readonly \Closure $build) {}

    public function getBody(): StreamInterface
    {
        return $this->body ??= ($this->build)();
    }

    public function getProtocolVersion(): string
    {
        return $this->response->getProtocolVersion();
    }

    public function withProtocolVersion(string $version): static
    {
        return $this->with($this->response->withProtocolVersion($version));
    }

    public function getHeaders(): array
    {
        return $this->response->getHeaders();
    }

    public function hasHeader(string $name): bool
    {
        return $this->response->hasHeader($name);
    }

    public function getHeader(string $name): array
    {
        return $this->response->getHeader($name);
    }

    public function getHeaderLine(string $name): string
    {
        return $this->response->getHeaderLine($name);
    }

    public function withHeader(string $name, $value): static
    {
        return $this->with($this->response->withHeader($name, $value));
    }

    public function withAddedHeader(string $name, $value): static
    {
        return $this->with($this->response->withAddedHeader($name, $value));
    }

    public function withoutHeader(string $name): static
    {
        return $this->with($this->response->withoutHeader($name));
    }

    public function withBody(StreamInterface $body): static
    {
        $new = clone $this;
        $new->body = $body;

        return $new;
    }

    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }

    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        return $this->with($this->response->withStatus($code, $reasonPhrase));
    }

    public function getReasonPhrase(): string
    {
        return $this->response->getReasonPhrase();
    }

    private function with(ResponseInterface $response): static
    {
        $new = clone $this;
        $new->response = $response;

        return $new;
    }
}
