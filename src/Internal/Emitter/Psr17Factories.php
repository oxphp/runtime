<?php
declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Emitter;

use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

/**
 * @internal Immutable group of the four PSR-17 factories a runner needs.
 * Some implementations (Nyholm, Guzzle) provide all four on a single class;
 * others (Laminas, HttpSoft) split them — this DTO hides the difference.
 */
final readonly class Psr17Factories
{
    public function __construct(
        public ServerRequestFactoryInterface $serverRequest,
        public StreamFactoryInterface $stream,
        public UploadedFileFactoryInterface $uploadedFile,
        public UriFactoryInterface $uri,
    ) {}

    public static function fromSingle(object $all): self
    {
        if (!$all instanceof ServerRequestFactoryInterface
            || !$all instanceof StreamFactoryInterface
            || !$all instanceof UploadedFileFactoryInterface
            || !$all instanceof UriFactoryInterface
        ) {
            throw new \InvalidArgumentException(
                'Factory object must implement all four PSR-17 factory interfaces; got '
                .\get_debug_type($all)
            );
        }
        return new self($all, $all, $all, $all);
    }

    public static function fromQuad(
        ServerRequestFactoryInterface $sr,
        StreamFactoryInterface $s,
        UploadedFileFactoryInterface $uf,
        UriFactoryInterface $u,
    ): self {
        return new self($sr, $s, $uf, $u);
    }
}
