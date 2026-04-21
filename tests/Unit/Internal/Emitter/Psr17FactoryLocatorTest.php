<?php
declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Emitter;

use Nyholm\Psr7\Factory\Psr17Factory;
use OxPHP\Runtime\Internal\Emitter\Psr17Factories;
use OxPHP\Runtime\Internal\Emitter\Psr17FactoryLocator;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

final class Psr17FactoryLocatorTest extends TestCase
{
    public function test_override_instance_takes_precedence(): void
    {
        $nyholm = new Psr17Factory();
        $factories = (new Psr17FactoryLocator($nyholm))->locate();

        self::assertSame($nyholm, $factories->serverRequest);
        self::assertSame($nyholm, $factories->stream);
        self::assertSame($nyholm, $factories->uploadedFile);
        self::assertSame($nyholm, $factories->uri);
    }

    public function test_override_fqcn_is_instantiated(): void
    {
        $factories = (new Psr17FactoryLocator(Psr17Factory::class))->locate();

        self::assertInstanceOf(Psr17Factory::class, $factories->serverRequest);
    }

    public function test_auto_discovery_finds_nyholm_when_available(): void
    {
        // Nyholm is in require-dev; default candidate order puts it first.
        $factories = (new Psr17FactoryLocator())->locate();

        self::assertInstanceOf(ServerRequestFactoryInterface::class, $factories->serverRequest);
        self::assertInstanceOf(StreamFactoryInterface::class, $factories->stream);
        self::assertInstanceOf(UploadedFileFactoryInterface::class, $factories->uploadedFile);
        self::assertInstanceOf(UriFactoryInterface::class, $factories->uri);
    }

    public function test_empty_candidate_list_throws_actionable_error(): void
    {
        $locator = new Psr17FactoryLocator(null, candidates: []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('composer require nyholm/psr7');

        $locator->locate();
    }
}
