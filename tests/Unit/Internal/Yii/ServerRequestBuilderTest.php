<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Yii;

use Nyholm\Psr7\Factory\Psr17Factory;
use OxPHP\Runtime\Internal\Runner\ServerParams;
use OxPHP\Runtime\Internal\Yii\ServerRequestBuilder;
use OxPHP\Runtime\Tests\Stub\FakeOxRequest;
use OxPHP\Runtime\Tests\Stub\OxPHPHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Yiisoft\Yii\Runner\Http\RequestFactory;

final class ServerRequestBuilderTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        OxPHPHarness::reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            \is_file($path) && \unlink($path);
        }
        $this->tempFiles = [];
    }

    #[DataProvider('provideMatches_yii_request_factory_for_the_same_http_requestCases')]
    public function test_matches_yii_request_factory_for_the_same_http_request(FakeOxRequest $ox): void
    {
        $files = $this->uploadFixture();

        self::assertSame(
            $this->snapshot($this->yiiRequest($ox, $files)),
            $this->snapshot($this->ours($ox, $files)),
        );
    }

    public static function provideMatches_yii_request_factory_for_the_same_http_requestCases(): iterable
    {
        yield 'GET with query, cookies, host' => [new FakeOxRequest(
            path: '/a',
            queryString: 'q=1',
            headers: ['host' => 'localhost', 'accept' => 'text/html'],
            cookies: ['s' => 'x'],
            query: ['q' => '1'],
        )];
        yield 'POST urlencoded' => [FakeOxRequest::postForm('/f', ['a' => '1', 'b' => ['c' => '2']])];
        yield 'POST multipart' => [new FakeOxRequest(
            method: 'POST',
            path: '/u',
            headers: ['content-type' => 'multipart/form-data; boundary=x'],
            payload: ['title' => 't'],
        )];
        yield 'POST JSON stays unparsed' => [new FakeOxRequest(
            method: 'POST',
            path: '/j',
            headers: ['content-type' => 'application/json'],
            payload: ['a' => 1],
            body: '{"a":1}',
        )];
        yield 'PUT urlencoded stays unparsed' => [new FakeOxRequest(
            method: 'PUT',
            path: '/p',
            headers: ['content-type' => 'application/x-www-form-urlencoded'],
            payload: ['a' => '1'],
            body: 'a=1',
        )];
    }

    public function test_uploads_are_keyed_and_nested_by_field_name(): void
    {
        $files = $this->ours(new FakeOxRequest(method: 'POST'), $this->uploadFixture())->getUploadedFiles();

        self::assertSame('me.jpg', $files['avatar']->getClientFilename());
        self::assertSame('b.png', $files['photos'][1]->getClientFilename());
        // same client name, both kept
        self::assertSame('me.jpg', $files['user']['avatar']->getClientFilename());
        self::assertSame(\UPLOAD_ERR_NO_FILE, $files['empty']->getError());
    }

    public function test_uploads_are_read_from_files_on_every_build(): void
    {
        $first = $this->ours(new FakeOxRequest(method: 'POST'), ['a' => $this->singleUpload('a.txt', 'one')]);
        $second = $this->ours(new FakeOxRequest(method: 'POST'), ['b' => $this->singleUpload('b.txt', 'two')]);

        self::assertSame(['a'], \array_keys($first->getUploadedFiles()));
        self::assertSame(['b'], \array_keys($second->getUploadedFiles()));
        self::assertSame('two', (string) $second->getUploadedFiles()['b']->getStream());
    }

    public function test_no_other_superglobal_reaches_the_request(): void
    {
        $saved = [$_GET, $_POST, $_COOKIE, $_SERVER];
        try {
            $_GET = ['leak' => '1'];
            $_POST = ['leak' => '1'];
            $_COOKIE = ['leak' => '1'];
            $_SERVER['HTTP_X_LEAK'] = '1';

            $request = $this->ours(FakeOxRequest::postForm('/', ['a' => '1']), []);
        } finally {
            [$_GET, $_POST, $_COOKIE, $_SERVER] = $saved;
        }

        self::assertSame([], $request->getQueryParams());
        self::assertSame(['a' => '1'], $request->getParsedBody());
        self::assertSame([], $request->getCookieParams());
        self::assertFalse($request->hasHeader('x-leak'));
        self::assertArrayNotHasKey('HTTP_X_LEAK', $request->getServerParams());
    }

    /** @param array<string, mixed> $files the `$_FILES` value to build uploads from */
    private function yiiRequest(FakeOxRequest $ox, array $files): ServerRequestInterface
    {
        $f = new Psr17Factory();
        $body = \fopen('php://memory', 'r+');
        \assert($body !== false);
        \fwrite($body, $ox->body());
        \rewind($body);

        $saved = [$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES];
        try {
            $_SERVER = ServerParams::build($ox, 'OxPHP/0.0.0-test');
            $_GET = $ox->query() ?? [];
            $_POST = \is_array($ox->payload()) ? $ox->payload() : [];
            $_COOKIE = $ox->cookies();
            $_FILES = $files;

            return new RequestFactory($f, $f, $f, $f)->create($body);
        } finally {
            [$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES] = $saved;
        }
    }

    /** @param array<string, mixed> $files the `$_FILES` value to build uploads from */
    private function ours(FakeOxRequest $ox, array $files): ServerRequestInterface
    {
        $f = new Psr17Factory();

        $saved = $_FILES;
        try {
            $_FILES = $files;

            return ServerRequestBuilder::build($ox, $f, $f, $f, 'OxPHP/0.0.0-test');
        } finally {
            $_FILES = $saved;
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(ServerRequestInterface $r): array
    {
        $headers = \array_change_key_case($r->getHeaders());
        \ksort($headers);

        return [
            'method' => $r->getMethod(),
            'uri' => (string) $r->getUri(),
            'protocol' => $r->getProtocolVersion(),
            'headers' => $headers,
            'server' => $r->getServerParams(),
            'query' => $r->getQueryParams(),
            'cookies' => $r->getCookieParams(),
            'parsedBody' => $r->getParsedBody(),
            'body' => (string) $r->getBody(),
            'files' => $this->describeFiles($r->getUploadedFiles()),
        ];
    }

    /**
     * @param array<array-key, mixed> $files
     * @return array<array-key, mixed>
     */
    private function describeFiles(array $files): array
    {
        return \array_map(
            fn (mixed $file): array => $file instanceof UploadedFileInterface
                ? [
                    $file->getClientFilename(),
                    $file->getClientMediaType(),
                    $file->getSize(),
                    $file->getError(),
                    // Nyholm throws on getStream() unless the upload succeeded
                    $file->getError() === \UPLOAD_ERR_OK ? (string) $file->getStream() : null,
                ]
                : $this->describeFiles($file),
            $files,
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function uploadFixture(): array
    {
        $avatar = $this->tempFile('abc');
        $a = $this->tempFile('def');
        $b = $this->tempFile('ghi');

        return [
            'avatar' => [
                'name' => 'me.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => $avatar,
                'error' => \UPLOAD_ERR_OK,
                'size' => 3,
            ],
            'photos' => [
                'name' => ['a.png', 'b.png'],
                'type' => ['image/png', 'image/png'],
                'tmp_name' => [$a, $b],
                'error' => [\UPLOAD_ERR_OK, \UPLOAD_ERR_OK],
                'size' => [3, 3],
            ],
            'user' => [
                'name' => ['avatar' => 'me.jpg'],
                'type' => ['avatar' => 'image/jpeg'],
                'tmp_name' => ['avatar' => $avatar],
                'error' => ['avatar' => \UPLOAD_ERR_OK],
                'size' => ['avatar' => 3],
            ],
            'empty' => [
                'name' => '',
                'type' => '',
                'tmp_name' => '',
                'error' => \UPLOAD_ERR_NO_FILE,
                'size' => 0,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function singleUpload(string $name, string $contents): array
    {
        return [
            'name' => $name,
            'type' => 'text/plain',
            'tmp_name' => $this->tempFile($contents),
            'error' => \UPLOAD_ERR_OK,
            'size' => \strlen($contents),
        ];
    }

    private function tempFile(string $contents): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'oxphp-upload-');
        self::assertIsString($path);
        $this->tempFiles[] = $path;
        \file_put_contents($path, $contents);

        return $path;
    }
}
