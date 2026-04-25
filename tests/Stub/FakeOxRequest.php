<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub;

use OxPHP\Http\AttributesInterface;
use OxPHP\Http\RequestInterface;
use OxPHP\Http\SessionInterface;
use OxPHP\Http\UploadedFileInterface;

final class FakeOxRequest implements RequestInterface
{
    /** @param array<string, string> $headers normalised lowercase names */
    public function __construct(
        private string $method = 'GET',
        private string $path = '/',
        private string $queryString = '',
        private string $scheme = 'http',
        private string $host = 'localhost',
        private int $port = 80,
        private string $httpProtocolVersion = '1.1',
        private array $headers = [],
        private array $cookies = [],
        private array $query = [],
        private mixed $payload = null,
        private array $files = [],
        private string $body = '',
        private string $ip = '127.0.0.1',
        private float $startTime = 1_700_000_000.0,
        private ?AttributesInterface $attributes = null,
        private ?SessionInterface $session = null,
    ) {}

    public static function get(string $path, array $query = []): self
    {
        return new self(
            method: 'GET',
            path: $path,
            query: $query,
            queryString: $query === [] ? '' : \http_build_query($query)
        );
    }

    public static function postForm(string $path, array $form): self
    {
        return new self(
            method: 'POST',
            path: $path,
            headers: ['content-type' => 'application/x-www-form-urlencoded'],
            payload: $form,
            body: \http_build_query($form),
        );
    }

    public function method(): string
    {
        return $this->method;
    }
    public function path(): string
    {
        return $this->path;
    }
    public function fullUri(): string
    {
        $port = ($this->scheme === 'http' && $this->port === 80) || ($this->scheme === 'https' && $this->port === 443)
            ? '' : ':' . $this->port;
        $qs = $this->queryString === '' ? '' : '?' . $this->queryString;
        return $this->scheme . '://' . $this->host . $port . $this->path . $qs;
    }
    public function scheme(): string
    {
        return $this->scheme;
    }
    public function host(): string
    {
        return $this->host;
    }
    public function port(): int
    {
        return $this->port;
    }
    public function queryString(): ?string
    {
        return $this->queryString === '' ? null : $this->queryString;
    }
    public function isSecure(): bool
    {
        return $this->scheme === 'https';
    }
    public function isMethod(string $method): bool
    {
        return \strcasecmp($this->method, $method) === 0;
    }
    public function httpProtocol(): string
    {
        return 'HTTP/' . $this->httpProtocolVersion;
    }
    public function httpProtocolVersion(): string
    {
        return $this->httpProtocolVersion;
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }
        return $this->query[$key] ?? $default;
    }

    public function payload(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->payload;
        }
        return \is_array($this->payload) ? ($this->payload[$key] ?? $default) : $default;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[\strtolower($name)] ?? $default;
    }
    public function headers(): array
    {
        return $this->headers;
    }
    public function hasHeader(string $name): bool
    {
        return isset($this->headers[\strtolower($name)]);
    }
    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }
    public function cookies(): array
    {
        return $this->cookies;
    }
    public function body(): string
    {
        return $this->body;
    }
    public function contentType(): ?string
    {
        return $this->header('content-type');
    }

    public function file(string $name): ?UploadedFileInterface
    {
        foreach ($this->files as $f) {
            if ($f instanceof UploadedFileInterface && $f->name() === $name) {
                return $f;
            }
        }
        return null;
    }
    public function files(?string $name = null): array
    {
        if ($name === null) {
            return $this->files;
        }
        $out = [];
        foreach ($this->files as $f) {
            if ($f instanceof UploadedFileInterface && $f->name() === $name) {
                $out[] = $f;
            }
        }
        return $out;
    }
    public function ip(): string
    {
        return $this->ip;
    }
    public function startTime(bool $asFloat = false): int|float
    {
        return $asFloat ? $this->startTime : (int) $this->startTime;
    }
    public function attributes(): AttributesInterface
    {
        return $this->attributes ??= new class () implements AttributesInterface {
            private array $store = [];
            public function get(string $key, mixed $default = null): mixed
            {
                return $this->store[$key] ?? $default;
            }
            public function set(string $key, mixed $value): void
            {
                $this->store[$key] = $value;
            }
            public function has(string $key): bool
            {
                return \array_key_exists($key, $this->store);
            }
            public function remove(string $key): void
            {
                unset($this->store[$key]);
            }
            public function all(): array
            {
                return $this->store;
            }
        };
    }
    public function session(): ?SessionInterface
    {
        return $this->session;
    }
}
