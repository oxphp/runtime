<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Emitter;

/**
 * @internal Discovers a PSR-17 factory set at runtime.
 *
 * Resolution order:
 *   1. Explicit override passed to the constructor (object or FQCN).
 *   2. Single-class implementations (Nyholm, Guzzle) — probed first to
 *      avoid unnecessary object instantiation.
 *   3. Split-class implementations (HttpSoft, Laminas) — wrapped into a
 *      Psr17Factories quad.
 *
 * If nothing is found, throws with a clear install instruction.
 */
final class Psr17FactoryLocator
{
    /**
     * @param object|string|null $override   pre-built factory instance or FQCN to instantiate
     * @param list<string>|null $candidates  class-name candidates; null uses the production list
     */
    public function __construct(
        private readonly object|string|null $override = null,
        private readonly ?array $candidates = null,
    ) {}

    public function locate(): Psr17Factories
    {
        if ($this->override !== null) {
            $obj = \is_object($this->override) ? $this->override : new ($this->override)();
            return Psr17Factories::fromSingle($obj);
        }

        foreach ($this->candidates ?? self::defaultCandidates() as $fqcn) {
            if (!\class_exists($fqcn)) {
                continue;
            }
            return $this->build($fqcn);
        }

        throw new \RuntimeException(
            'No PSR-17 factory found. Run: composer require nyholm/psr7'
        );
    }

    /** @return list<string> */
    private static function defaultCandidates(): array
    {
        return [
            'Nyholm\\Psr7\\Factory\\Psr17Factory',
            'GuzzleHttp\\Psr7\\HttpFactory',
            'HttpSoft\\Message\\ServerRequestFactory',
            'Laminas\\Diactoros\\ServerRequestFactory',
        ];
    }

    private function build(string $fqcn): Psr17Factories
    {
        return match ($fqcn) {
            'HttpSoft\\Message\\ServerRequestFactory' => Psr17Factories::fromQuad(
                new \HttpSoft\Message\ServerRequestFactory(),
                new \HttpSoft\Message\StreamFactory(),
                new \HttpSoft\Message\UploadedFileFactory(),
                new \HttpSoft\Message\UriFactory(),
            ),
            'Laminas\\Diactoros\\ServerRequestFactory' => Psr17Factories::fromQuad(
                new \Laminas\Diactoros\ServerRequestFactory(),
                new \Laminas\Diactoros\StreamFactory(),
                new \Laminas\Diactoros\UploadedFileFactory(),
                new \Laminas\Diactoros\UriFactory(),
            ),
            default => Psr17Factories::fromSingle(new $fqcn()),
        };
    }
}
