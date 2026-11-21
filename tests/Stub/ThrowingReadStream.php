<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub;

use Psr\Http\Message\StreamInterface;

/**
 * Reports `$size` bytes and reads `$goodBytes` of 'x', then throws: a body that fails
 * partway through being read.
 */
final class ThrowingReadStream implements StreamInterface
{
    private int $read = 0;

    public function __construct(private readonly int $size, private readonly int $goodBytes) {}

    public function __toString(): string
    {
        return '';
    }

    public function close(): void {}

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function tell(): int
    {
        return $this->read;
    }

    public function eof(): bool
    {
        return $this->read >= $this->size;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        throw new \RuntimeException('Not seekable');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('Not seekable');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('Not writable');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        if ($this->read >= $this->goodBytes) {
            throw new \RuntimeException('Read failed after ' . $this->read . ' bytes');
        }
        $n = \min($length, $this->goodBytes - $this->read);
        $this->read += $n;

        return \str_repeat('x', $n);
    }

    public function getContents(): string
    {
        $contents = '';
        while (!$this->eof()) {
            $contents .= $this->read(8192);
        }

        return $contents;
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
