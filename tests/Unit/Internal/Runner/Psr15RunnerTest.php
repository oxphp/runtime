<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Runner;

use Nyholm\Psr7\Factory\Psr17Factory;
use OxPHP\Runtime\Internal\Bridge\OxPHP;
use OxPHP\Runtime\Internal\Emitter\Psr17FactoryLocator;
use OxPHP\Runtime\Internal\Runner\Psr15Runner;
use OxPHP\Runtime\Tests\Stub\Apps\FakePsr15Handler;
use OxPHP\Runtime\Tests\Stub\FakeOxRequest;
use OxPHP\Runtime\Tests\Stub\OxPHPHarness;
use OxPHP\Runtime\Tests\Stub\UploadedFilesFixture;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

final class Psr15RunnerTest extends TestCase
{
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
}
