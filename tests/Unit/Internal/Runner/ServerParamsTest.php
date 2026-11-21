<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Runner;

use OxPHP\Runtime\Internal\Runner\ServerParams;
use OxPHP\Runtime\Tests\Stub\FakeOxRequest;
use PHPUnit\Framework\TestCase;

final class ServerParamsTest extends TestCase
{
    public function test_builds_the_same_server_params_as_the_http_foundation_builder_did(): void
    {
        $ox = new FakeOxRequest(
            method: 'GET',
            path: '/p',
            queryString: 'a=1',
            scheme: 'https',
            host: 'example.com',
            port: 8443,
            headers: ['accept' => 'text/html', 'x-forwarded-for' => '1.2.3.4'],
            ip: '10.0.0.5',
        );

        $server = ServerParams::build($ox, 'OxPHP/9.9.9');

        self::assertSame('/p?a=1', $server['REQUEST_URI']);
        self::assertSame('example.com:8443', $server['HTTP_HOST']);
        self::assertSame('8443', $server['SERVER_PORT']);
        self::assertSame('on', $server['HTTPS']);
        self::assertSame('10.0.0.5', $server['REMOTE_ADDR']);
        self::assertSame('OxPHP/9.9.9', $server['SERVER_SOFTWARE']);
        self::assertSame('1.2.3.4', $server['HTTP_X_FORWARDED_FOR']);
        self::assertSame(1_700_000_000, $server['REQUEST_TIME']);
    }
}
