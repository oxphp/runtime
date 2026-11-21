<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Runner;

use Illuminate\Http\Request as IlluminateRequest;
use OxPHP\Runtime\Internal\Runner\HttpFoundationRequestBuilder;
use OxPHP\Runtime\Tests\Stub\CapturedOxPHPServer;
use PHPUnit\Framework\TestCase;

final class HttpFoundationRequestBuilderTest extends TestCase
{
    public function test_a_request_without_a_host_header_is_for_localhost_in_symfony_and_laravel(): void
    {
        // HTTP/1.0 lets a request leave out Host, as some load balancers' health checks do.
        [$ox] = CapturedOxPHPServer::http10WithoutHost();

        $symfony = new HttpFoundationRequestBuilder(CapturedOxPHPServer::SOFTWARE)->build($ox);
        $laravel = IlluminateRequest::createFromBase($symfony);

        self::assertSame('localhost', $symfony->getHost());
        // Without a Host header, Symfony takes SERVER_PORT as given: a string, as under FPM.
        self::assertSame('80', $symfony->getPort());
        self::assertSame('http://localhost/server.php?a=1', $symfony->getUri());
        self::assertSame('localhost', $laravel->getHost());
        self::assertSame('http://localhost/server.php?a=1', $laravel->fullUrl());
    }
}
