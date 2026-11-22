<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Runner;

use Nyholm\Psr7\Factory\Psr17Factory;
use OxPHP\Runtime\Internal\Bridge\OxPHP;
use OxPHP\Runtime\Internal\Emitter\Psr17FactoryLocator;
use OxPHP\Runtime\Internal\Runner\Psr15Runner;
use OxPHP\Runtime\Tests\Stub\Apps\FakePsr15Handler;
use OxPHP\Runtime\Tests\Stub\CapturedOxPHPServer;
use OxPHP\Runtime\Tests\Stub\FakeOxRequest;
use OxPHP\Runtime\Tests\Stub\MisreportedSizeStream;
use OxPHP\Runtime\Tests\Stub\OxPHPHarness;
use OxPHP\Runtime\Tests\Stub\RunsUnderPhpCgi;
use OxPHP\Runtime\Tests\Stub\ThrowingReadStream;
use OxPHP\Runtime\Tests\Stub\UnknownSizeStream;
use OxPHP\Runtime\Tests\Stub\UploadedFilesFixture;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

final class Psr15RunnerTest extends TestCase
{
    use RunsUnderPhpCgi;

    private UploadedFilesFixture $uploads;

    protected function setUp(): void
    {
        OxPHPHarness::reset();
        $this->uploads = new UploadedFilesFixture();
    }

    protected function tearDown(): void
    {
        OxPHPHarness::reset();
        $_FILES = [];
        $this->uploads->cleanUp();
    }

    public function test_one_shot_builds_psr7_request_from_oxphp_request_and_emits_response(): void
    {
        $harness = OxPHPHarness::instance();
        $harness->setCurrentRequest(FakeOxRequest::get('/hello', ['q' => 'v']));

        $handler = FakePsr15Handler::echoingPath();
        $runner = new Psr15Runner($handler, new OxPHP(), new Psr17FactoryLocator(), userResetters: []);

        \ob_start();
        $code = $runner->run();
        $out = (string) \ob_get_clean();

        self::assertSame(0, $code);
        self::assertSame('psr15:/hello', $out);
        self::assertCount(1, $handler->received);
        self::assertSame('GET', $handler->received[0]->getMethod());
        self::assertSame(['v'], $handler->received[0]->getQueryParams()['q'] ? ['v'] : ['']);
    }

    public function test_worker_loop_handles_multiple_requests_and_resets_between(): void
    {
        $h = OxPHPHarness::instance();
        $h->setWorker(true);
        $h->pushRequest(FakeOxRequest::get('/a'));
        $h->pushRequest(FakeOxRequest::get('/b'));

        $resets = 0;
        $handler = FakePsr15Handler::echoingPath();
        $runner = new Psr15Runner(
            $handler,
            new OxPHP(),
            new Psr17FactoryLocator(),
            userResetters: [static function () use (&$resets): void {
                $resets++;
            }],
        );

        $runner->run();

        self::assertSame(['psr15:/a', 'psr15:/b'], $h->capturedOutputs());
        self::assertSame(2, $resets);
    }

    public function test_worker_passes_each_response_status_to_the_sapi(): void
    {
        $h = OxPHPHarness::instance();
        $h->setWorker(true);
        foreach (['/404', '/302', '/418', '/200'] as $path) {
            $h->pushRequest(FakeOxRequest::get($path));
        }

        $f = new Psr17Factory();
        $handler = new FakePsr15Handler(
            static fn (ServerRequestInterface $r): ResponseInterface => $f->createResponse((int) \substr($r->getUri()->getPath(), 1)),
        );

        new Psr15Runner($handler, new OxPHP(), new Psr17FactoryLocator(), userResetters: [])->run();

        self::assertSame([404, 302, 418, 200], $h->capturedStatuses());
    }

    public function test_parsed_body_passed_when_form_post(): void
    {
        OxPHPHarness::instance()->setCurrentRequest(
            FakeOxRequest::postForm('/submit', ['a' => '1', 'b' => '2'])
        );

        $captured = null;
        $handler = new FakePsr15Handler(static function ($req) use (&$captured) {
            $captured = $req->getParsedBody();
            $f = new \Nyholm\Psr7\Factory\Psr17Factory();
            return $f->createResponse(204);
        });

        \ob_start();
        new Psr15Runner($handler, new OxPHP(), new Psr17FactoryLocator(), userResetters: [])->run();
        \ob_end_clean();

        self::assertSame(['a' => '1', 'b' => '2'], $captured);
    }

    public function test_uploaded_file_is_keyed_by_its_form_field_name(): void
    {
        $files = $this->handleUpload($this->uploads->form());

        self::assertSame(['avatar', 'photos', 'user', 'deep', 'empty'], \array_keys($files));
        self::assertSame(['me.jpg', 'image/jpeg', 12, 0, 'hello-avatar'], self::describe($files['avatar']));
    }

    public function test_array_field_keeps_every_file_in_order(): void
    {
        $files = $this->handleUpload($this->uploads->form());

        self::assertCount(2, $files['photos']);
        self::assertSame(['p1.jpg', 'image/jpeg', 9, 0, 'photo-one'], self::describe($files['photos'][0]));
        self::assertSame(['p2.jpg', 'image/png', 9, 0, 'photo-two'], self::describe($files['photos'][1]));
    }

    public function test_nested_fields_keep_their_structure(): void
    {
        $files = $this->handleUpload($this->uploads->form());

        self::assertSame(['me.jpg', 'image/jpeg', 13, 0, 'nested-avatar'], self::describe($files['user']['avatar']));
        self::assertSame(['deep.txt', 'text/plain', 9, 0, 'deep-file'], self::describe($files['deep']['a']['b']));
    }

    public function test_files_sharing_a_client_filename_are_both_kept(): void
    {
        $files = $this->handleUpload($this->uploads->form());

        self::assertSame('hello-avatar', self::describe($files['avatar'])[4]);
        self::assertSame('nested-avatar', self::describe($files['user']['avatar'])[4]);
    }

    public function test_file_input_left_empty_is_an_upload_error_not_a_crash(): void
    {
        $files = $this->handleUpload($this->uploads->form());

        self::assertInstanceOf(UploadedFileInterface::class, $files['empty']);
        self::assertSame(\UPLOAD_ERR_NO_FILE, $files['empty']->getError());
        self::assertSame('', $files['empty']->getClientFilename());
        self::assertSame(0, $files['empty']->getSize());
    }

    public function test_worker_reads_the_uploads_of_each_request(): void
    {
        $h = OxPHPHarness::instance();
        $h->setWorker(true);
        $h->pushRequest(new FakeOxRequest(method: 'POST', path: '/a'), $this->uploads->single('doc', 'a.txt', 'first'));
        $h->pushRequest(new FakeOxRequest(method: 'POST', path: '/b'), $this->uploads->single('doc', 'b.txt', 'second'));

        $handler = FakePsr15Handler::echoingPath();
        new Psr15Runner($handler, new OxPHP(), new Psr17FactoryLocator(), userResetters: [])->run();

        self::assertSame(['a.txt', 'text/plain', 5, 0, 'first'], self::describe($handler->received[0]->getUploadedFiles()['doc'] ?? null));
        self::assertSame(['b.txt', 'text/plain', 6, 0, 'second'], self::describe($handler->received[1]->getUploadedFiles()['doc'] ?? null));
    }

    /**
     * @param array<string, mixed> $files the request's $_FILES
     * @return array<string, mixed> the built request's uploaded files
     */
    private function handleUpload(array $files): array
    {
        $_FILES = $files;
        OxPHPHarness::instance()->setCurrentRequest(new FakeOxRequest(method: 'POST', path: '/upload'));
        $handler = FakePsr15Handler::echoingPath();

        \ob_start();
        new Psr15Runner($handler, new OxPHP(), new Psr17FactoryLocator(), userResetters: [])->run();
        \ob_end_clean();

        return $handler->received[0]->getUploadedFiles();
    }

    /** @return array{?string, ?string, ?int, int, string} client name, client type, size, error, contents */
    private static function describe(mixed $file): array
    {
        self::assertInstanceOf(UploadedFileInterface::class, $file);

        return [$file->getClientFilename(), $file->getClientMediaType(), $file->getSize(), $file->getError(), (string) $file->getStream()];
    }

    public function test_a_body_that_cannot_be_read_is_sent_empty_with_the_response_status(): void
    {
        $h = OxPHPHarness::instance();
        $h->setWorker(true);
        $h->pushRequest(FakeOxRequest::get('/'));
        $path = (string) \tempnam(\sys_get_temp_dir(), 'oxphp-runtime-test');
        $f = new Psr17Factory();
        $handler = new FakePsr15Handler(
            static fn (): ResponseInterface => $f->createResponse(201)->withBody($f->createStreamFromFile($path, 'w')),
        );

        try {
            new Psr15Runner($handler, new OxPHP(), new Psr17FactoryLocator(), userResetters: [])->run();
        } finally {
            \unlink($path);
        }

        self::assertSame([201], $h->capturedStatuses());
        self::assertSame([''], $h->capturedOutputs());
    }

    public function test_a_body_of_known_size_is_sent_whole_and_one_of_unknown_size_is_flushed_per_chunk(): void
    {
        $h = OxPHPHarness::instance();
        $h->setWorker(true);
        $h->pushRequest(FakeOxRequest::get('/known'))->pushRequest(FakeOxRequest::get('/unknown'));
        $f = new Psr17Factory();
        $flushes = [];
        $handler = new FakePsr15Handler(static function (ServerRequestInterface $r) use ($f, $h, &$flushes): ResponseInterface {
            $flushes[] = $h->flushCount();
            $body = $f->createStream(\str_repeat('x', 20000));

            return $f->createResponse()->withBody($r->getUri()->getPath() === '/known' ? $body : new UnknownSizeStream($body));
        });

        new Psr15Runner($handler, new OxPHP(), new Psr17FactoryLocator(), userResetters: [])->run();
        $flushes[] = $h->flushCount();

        self::assertSame([20000, 20000], \array_map(\strlen(...), $h->capturedOutputs()));
        // Flushes before each request and after the last: none for the known size, one per chunk for the unknown.
        self::assertSame([0, 0, 3], $flushes);
    }

    public function test_a_piped_body_reporting_size_0_is_sent_whole_up_to_the_limit_and_flushed_per_chunk_past_it(): void
    {
        $h = OxPHPHarness::instance();
        $h->setWorker(true);
        $h->pushRequest(FakeOxRequest::get('/small'))->pushRequest(FakeOxRequest::get('/large'));
        $small = \str_repeat('s', 20000);
        $large = \str_repeat('0123456789abcdef', 196608) . \str_repeat('L', 20000); // 3 MiB + 20000
        $flushes = [];
        $handler = new FakePsr15Handler(static function (ServerRequestInterface $r) use ($h, $small, $large, &$flushes): ResponseInterface {
            $flushes[] = $h->flushCount();

            return new Psr17Factory()->createResponse()->withBody(MisreportedSizeStream::pipe($r->getUri()->getPath() === '/small' ? $small : $large));
        });

        new Psr15Runner($handler, new OxPHP(), new Psr17FactoryLocator(), userResetters: [])->run();
        $flushes[] = $h->flushCount();

        self::assertSame([\md5($small), \md5($large)], \array_map(\md5(...), $h->capturedOutputs()));
        // None for the small body; past the limit, one for what was read ahead, then one per 8 KiB chunk.
        self::assertSame([0, 0, 4], $flushes);
    }

    public function test_a_body_that_fails_after_its_first_chunk_ends_there_and_the_next_request_is_served(): void
    {
        $r = self::runUnderPhpCgi('failAfterTheFirstChunkThenServeAnother');

        self::assertSame(200, $r['status'], $r['stderr']);
        // The first chunk, then the next request's body: nothing between them.
        self::assertSame('xxxxxxxxxxpsr15:/ok', $r['body']);
        self::assertSame(1, \substr_count($r['stderr'], 'Read failed after 10 bytes'));
        self::assertStringNotContainsString('Warning', $r['stderr']);
    }

    /**
     * Run under php-cgi by the test above: a worker whose first response reports more
     * than the server compresses, so it streams from the first chunk, and fails to read
     * the second; the next request succeeds. Both write to the one CGI response.
     */
    public static function failAfterTheFirstChunkThenServeAnother(): void
    {
        OxPHPHarness::instance()
            ->setWorker(true)
            ->setPassOutputThrough(true)
            ->pushRequest(FakeOxRequest::get('/export'))
            ->pushRequest(FakeOxRequest::get('/ok'));
        $f = new Psr17Factory();
        $handler = new FakePsr15Handler(static fn (ServerRequestInterface $r): ResponseInterface => $r->getUri()->getPath() === '/export'
            ? $f->createResponse()->withBody(new ThrowingReadStream(4 * 1024 * 1024, 10))
            : $f->createResponse()->withBody($f->createStream('psr15:/ok')));

        new Psr15Runner($handler, new OxPHP(), new Psr17FactoryLocator(), userResetters: [])->run();
    }

    public function test_a_request_without_a_host_header_is_served_with_localhost_as_the_uri_host(): void
    {
        $h = OxPHPHarness::instance();
        $h->setWorker(true);
        // HTTP/1.0 lets a request leave out Host, as some load balancers' health checks do.
        $h->pushRequest(CapturedOxPHPServer::http10WithoutHost()[0])->pushRequest(FakeOxRequest::get('/next'));
        $f = new Psr17Factory();
        $handler = new FakePsr15Handler(static fn (ServerRequestInterface $r): ResponseInterface => $f->createResponse()->withBody($f->createStream((string) $r->getUri())));

        new Psr15Runner($handler, new OxPHP(), new Psr17FactoryLocator(), userResetters: [])->run();

        self::assertSame([200, 200], $h->capturedStatuses());
        self::assertSame(['http://localhost/server.php?a=1', 'http://localhost/next'], $h->capturedOutputs());
    }

    public function test_the_request_carries_the_server_params_oxphp_registers_except_what_the_request_api_has_no_source_for(): void
    {
        [$ox, $real] = CapturedOxPHPServer::post();
        OxPHPHarness::instance()->setCurrentRequest($ox);
        $handler = FakePsr15Handler::echoingPath();

        \ob_start();

        try {
            new Psr15Runner($handler, new OxPHP(), new Psr17FactoryLocator(), userResetters: [])->run();
        } finally {
            \ob_end_clean();
        }

        $expected = ['SERVER_SOFTWARE' => 'OxPHP/0.0.0-test'] + \array_diff_key($real, \array_flip(CapturedOxPHPServer::NOT_FROM_THE_API));
        \ksort($expected);
        $server = $handler->received[0]->getServerParams();
        \ksort($server);
        self::assertSame($expected, $server);
    }

    public function test_response_headers_replace_the_session_cache_limiters_and_keep_the_session_cookie(): void
    {
        if (!\function_exists('session_start')) {
            self::markTestSkipped('ext-session not available');
        }

        $r = self::runUnderPhpCgi('handleAfterSessionStart');

        self::assertSame(200, $r['status'], $r['stderr']);
        self::assertSame('', $r['stderr']);
        self::assertSame(['public, max-age=60'], self::headerValues($r['headers'], 'Cache-Control'));
        // The body is the session id, so the session cookie can be told apart.
        self::assertSame(['PHPSESSID=' . $r['body'] . '; path=/', 'theme=dark'], self::headerValues($r['headers'], 'Set-Cookie'));
    }

    /**
     * Run under php-cgi by the test above: one traditional-mode request whose handler
     * starts a native session, so PHP queues the session cookie and the default cache
     * limiter's headers before the response is emitted.
     */
    public static function handleAfterSessionStart(): void
    {
        OxPHPHarness::instance()->setCurrentRequest(FakeOxRequest::get('/'));
        $f = new Psr17Factory();
        $handler = new FakePsr15Handler(static function () use ($f): ResponseInterface {
            \session_start(['save_path' => \sys_get_temp_dir()]);

            return $f->createResponse()
                ->withHeader('Cache-Control', 'public, max-age=60')
                ->withHeader('Set-Cookie', 'theme=dark')
                ->withBody($f->createStream((string) \session_id()));
        });

        try {
            new Psr15Runner($handler, new OxPHP(), new Psr17FactoryLocator(), userResetters: [])->run();
        } finally {
            \session_destroy();
        }
    }
}
