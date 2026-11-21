<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Runner;

use OxPHP\Runtime\Internal\Runner\ServerParams;
use OxPHP\Runtime\Tests\Stub\CapturedOxPHPServer;
use OxPHP\Runtime\Tests\Stub\FakeOxRequest;
use PHPUnit\Framework\Attributes\DataProvider;
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
            headers: ['host' => 'example.com:8443', 'accept' => 'text/html', 'x-forwarded-for' => '1.2.3.4'],
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

    /**
     * @return iterable<string, array{FakeOxRequest, array<string, int|float|string>}>
     */
    public static function provideMatches_the_server_oxphp_registers_except_what_the_request_api_has_no_source_forCases(): iterable
    {
        yield 'GET with a cookie and header names with _ or .' => CapturedOxPHPServer::get();
        yield 'POST form' => CapturedOxPHPServer::post();
        yield 'host and port from the Host header' => CapturedOxPHPServer::hostWithPort();
        yield 'HTTP/1.0 without a Host header' => CapturedOxPHPServer::http10WithoutHost();
    }

    /**
     * @param array<string, int|float|string> $real
     */
    #[DataProvider('provideMatches_the_server_oxphp_registers_except_what_the_request_api_has_no_source_forCases')]
    public function test_matches_the_server_oxphp_registers_except_what_the_request_api_has_no_source_for(FakeOxRequest $ox, array $real): void
    {
        self::assertSame([], \array_diff(CapturedOxPHPServer::NOT_FROM_THE_API, \array_keys($real)));
        $expected = \array_diff_key($real, \array_flip(CapturedOxPHPServer::NOT_FROM_THE_API));
        \ksort($expected);

        $server = ServerParams::build($ox, CapturedOxPHPServer::SOFTWARE);
        \ksort($server);

        self::assertSame($expected, $server);
    }

    public function test_a_header_name_with_an_underscore_or_a_dot_cannot_stand_in_for_the_dashed_one(): void
    {
        $ox = new FakeOxRequest(headers: [
            'x-forwarded-for' => '10.0.0.1',
            'x_forwarded_for' => '6.6.6.6',
            'x.forwarded.for' => '7.7.7.7',
        ]);

        $server = ServerParams::build($ox, 'OxPHP/9.9.9');

        self::assertSame('10.0.0.1', $server['HTTP_X_FORWARDED_FOR']);
        self::assertSame([], \preg_grep('/FORWARDED/', \array_diff(\array_keys($server), ['HTTP_X_FORWARDED_FOR'])));
    }
}
