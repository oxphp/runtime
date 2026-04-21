<?php
declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub;

use OxPHP\Http\UploadedFileInterface;

final class FakeUploadedFile implements UploadedFileInterface
{
    public function __construct(
        private readonly string $name = 'upload.bin',
        private readonly string $clientType = 'application/octet-stream',
        private readonly string $detectedType = 'application/octet-stream',
        private readonly int $size = 0,
        private readonly string $tmpPath = '/tmp/fake',
        private readonly int $error = \UPLOAD_ERR_OK,
    ) {}

    public function name(): string { return $this->name; }
    public function clientType(): string { return $this->clientType; }
    public function type(): string { return $this->detectedType; }
    public function size(): int { return $this->size; }
    public function tmpPath(): string { return $this->tmpPath; }
    public function error(): int { return $this->error; }
    public function isValid(): bool { return $this->error === \UPLOAD_ERR_OK; }
    public function moveTo(string $path): bool { return $this->isValid(); }
}
