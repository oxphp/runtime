<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Yii;

use Nyholm\Psr7\Factory\Psr17Factory;
use OxPHP\Runtime\Internal\Yii\ResponsePreparer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResponsePreparerTest extends TestCase
{
    public function test_head_keeps_the_full_content_length_and_drops_the_body(): void
    {
        $f = new Psr17Factory();
        $r = ResponsePreparer::prepare($f->createServerRequest('HEAD', '/'), $f->createResponse(200)->withBody($f->createStream('hello')), $f);

        self::assertSame('5', $r->getHeaderLine('Content-Length'));
        self::assertSame('', (string) $r->getBody());
    }

    #[DataProvider('provideNo_body_statuses_lose_the_body_and_get_no_content_lengthCases')]
    public function test_no_body_statuses_lose_the_body_and_get_no_content_length(int $status): void
    {
        $f = new Psr17Factory();
        $r = ResponsePreparer::prepare($f->createServerRequest('GET', '/'), $f->createResponse($status)->withBody($f->createStream('hello')), $f);

        self::assertSame('', (string) $r->getBody());
        self::assertFalse($r->hasHeader('Content-Length'));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideNo_body_statuses_lose_the_body_and_get_no_content_lengthCases(): iterable
    {
        foreach ([100, 101, 102, 204, 205, 304] as $status) {
            yield (string) $status => [$status];
        }
    }

    public function test_transfer_encoding_drops_content_length(): void
    {
        $f = new Psr17Factory();
        $response = $f->createResponse(200)
            ->withHeader('Transfer-Encoding', 'chunked')
            ->withHeader('Content-Length', '5')
            ->withBody($f->createStream('hello'));

        $r = ResponsePreparer::prepare($f->createServerRequest('GET', '/'), $response, $f);

        self::assertFalse($r->hasHeader('Content-Length'));
        self::assertSame('chunked', $r->getHeaderLine('Transfer-Encoding'));
        self::assertSame('hello', (string) $r->getBody());
    }

    public function test_an_existing_content_length_is_kept(): void
    {
        $f = new Psr17Factory();
        $response = $f->createResponse(200)->withHeader('Content-Length', '99')->withBody($f->createStream('hello'));

        $r = ResponsePreparer::prepare($f->createServerRequest('GET', '/'), $response, $f);

        self::assertSame('99', $r->getHeaderLine('Content-Length'));
    }

    public function test_an_empty_body_gets_no_content_length(): void
    {
        $f = new Psr17Factory();
        $r = ResponsePreparer::prepare($f->createServerRequest('GET', '/'), $f->createResponse(200)->withBody($f->createStream('')), $f);

        self::assertFalse($r->hasHeader('Content-Length'));
    }
}
