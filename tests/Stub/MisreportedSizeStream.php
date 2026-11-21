<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\StreamInterface;

/**
 * Wraps a stream and reports the size and seekability it is given, as a pipe or a
 * socket does: `fstat()` gives them size 0, and Nyholm and Guzzle cache that. When it
 * reports not seekable, seeking throws. Records how it was read.
 */
final class MisreportedSizeStream implements StreamInterface
{
    /** @var list<int> the length passed to each read() */
    public private(set) array $reads = [];

    public private(set) bool $readWhole = false;

    public function __construct(
        private readonly StreamInterface $inner,
        private readonly ?int $size,
        private readonly bool $seekable,
    ) {}

    public static function pipe(string $contents): self
    {
        return new self(new Psr17Factory()->createStream($contents), 0, false);
    }

    public function __toString(): string
    {
        $this->readWhole = true;

        return (string) $this->inner;
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function detach()
    {
        return $this->inner->detach();
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function tell(): int
    {
        return $this->inner->tell();
    }

    public function eof(): bool
    {
        return $this->inner->eof();
    }

    public function isSeekable(): bool
    {
        return $this->seekable;
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        if (!$this->seekable) {
            throw new \RuntimeException('Stream is not seekable');
        }
        $this->inner->seek($offset, $whence);
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('Stream is not writable');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        $this->reads[] = $length;

        return $this->inner->read($length);
    }

    public function getContents(): string
    {
        $this->readWhole = true;

        return $this->inner->getContents();
    }

    public function getMetadata(?string $key = null)
    {
        return $this->inner->getMetadata($key);
    }
}
