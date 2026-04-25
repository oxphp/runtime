<?php

declare(strict_types=1);

/**
 * Polyfill for the OxPHP\Http\* interfaces provided by the oxphp_sapi PHP extension.
 *
 * Loaded only when the real extension is not present (e.g. running the test suite
 * on a developer machine or CI job without oxphp_sapi). The single source of truth
 * for these signatures is oxphp.stub.php in the oxphp server repository — keep
 * both files in sync.
 */

namespace OxPHP\Http {

    interface RequestInterface
    {
        public function method(): string;
        public function path(): string;
        public function fullUri(): string;
        public function scheme(): string;
        public function host(): string;
        public function port(): int;
        public function queryString(): ?string;
        public function isSecure(): bool;
        public function isMethod(string $method): bool;
        public function httpProtocol(): string;
        public function httpProtocolVersion(): string;
        public function query(?string $key = null, mixed $default = null): mixed;
        public function payload(?string $key = null, mixed $default = null): mixed;
        public function header(string $name, ?string $default = null): ?string;
        public function headers(): array;
        public function hasHeader(string $name): bool;
        public function cookie(string $name, ?string $default = null): ?string;
        public function cookies(): array;
        public function body(): string;
        public function contentType(): ?string;
        public function file(string $name): ?UploadedFileInterface;
        public function files(?string $name = null): array;
        public function ip(): string;
        public function startTime(bool $asFloat = false): int|float;
        public function attributes(): AttributesInterface;
        public function session(): ?SessionInterface;
    }

    interface SessionInterface
    {
        public function id(): string;
        public function name(): string;
        public function get(string $key, mixed $default = null): mixed;
        public function has(string $key): bool;
        public function all(): array;
    }

    interface UploadedFileInterface
    {
        public function name(): string;
        public function clientType(): string;
        public function type(): string;
        public function size(): int;
        public function tmpPath(): string;
        public function error(): int;
        public function isValid(): bool;
        public function moveTo(string $path): bool;
    }

    interface AttributesInterface
    {
        public function get(string $key, mixed $default = null): mixed;
        public function set(string $key, mixed $value): void;
        public function has(string $key): bool;
        public function remove(string $key): void;
        public function all(): array;
    }
}
