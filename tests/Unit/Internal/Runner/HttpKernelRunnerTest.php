<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Runner;

use OxPHP\Runtime\Internal\Bridge\OxPHP;
use OxPHP\Runtime\Internal\Runner\HttpKernelRunner;
use OxPHP\Runtime\Tests\Stub\Apps\FakeSymfonyKernel;
use OxPHP\Runtime\Tests\Stub\FakeOxRequest;
use OxPHP\Runtime\Tests\Stub\OxPHPHarness;
use OxPHP\Runtime\Tests\Stub\UploadedFilesFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class HttpKernelRunnerTest extends TestCase
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

    public function test_synthesises_server_vars_and_emits_response(): void
    {
        OxPHPHarness::instance()->setCurrentRequest(FakeOxRequest::get('/hello'));

        $kernel = FakeSymfonyKernel::echoingPath();
        $runner = new HttpKernelRunner($kernel, new OxPHP(), userResetters: []);

        \ob_start();
        $runner->run();
        $out = (string) \ob_get_clean();

        self::assertSame('sym:/hello', $out);
        self::assertCount(1, $kernel->handled);
        self::assertSame('GET', $kernel->handled[0]->getMethod());
        self::assertSame('/hello', $kernel->handled[0]->getPathInfo());
    }

    public function test_request_carries_server_software(): void
    {
        OxPHPHarness::instance()->setCurrentRequest(FakeOxRequest::get('/'));
        $kernel = FakeSymfonyKernel::echoingPath();

        \ob_start();
        new HttpKernelRunner($kernel, new OxPHP(), userResetters: [])->run();
        \ob_end_clean();

        self::assertSame('OxPHP/0.0.0-test', $kernel->handled[0]->server->get('SERVER_SOFTWARE'));
    }

    public function test_terminate_called_after_emit_on_terminable_kernel(): void
    {
        OxPHPHarness::instance()->setCurrentRequest(FakeOxRequest::get('/'));
        $kernel = FakeSymfonyKernel::echoingPath();

        \ob_start();
        new HttpKernelRunner($kernel, new OxPHP(), userResetters: [])->run();
        \ob_end_clean();

        self::assertCount(1, $kernel->terminated);
    }

    public function test_streamed_response_interleaves_emission_with_callback(): void
    {
        OxPHPHarness::instance()->setCurrentRequest(FakeOxRequest::get('/sse'));

        $events = [];
        $kernel = new FakeSymfonyKernel(static function () use (&$events): Response {
            return new StreamedResponse(static function () use (&$events): void {
                echo 'one';
                $events[] = 'cb:one flushes=' . OxPHPHarness::instance()->flushCount();
                echo 'two';
                $events[] = 'cb:two flushes=' . OxPHPHarness::instance()->flushCount();
            });
        });

        \ob_start();
        // Observer below the runtime's handler: records each chunk the
        // instant the engine writes it through to the next output level.
        \ob_start(static function (string $b) use (&$events): string {
            if ($b !== '') {
                $events[] = 'wire:' . $b;
            }
            return $b;
        }, 1);
        new HttpKernelRunner($kernel, new OxPHP(), userResetters: [])->run();
        \ob_end_flush();
        $out = (string) \ob_get_clean();

        self::assertSame('onetwo', $out);
        self::assertSame(
            ['wire:one', 'cb:one flushes=0', 'wire:two', 'cb:two flushes=0'],
            $events,
        );
        self::assertSame(1, OxPHPHarness::instance()->flushCount());
        self::assertCount(1, $kernel->terminated);
    }

    public function test_worker_survives_mid_stream_exception(): void
    {
        $h = OxPHPHarness::instance();
        $h->setWorker(true);
        $h->pushRequest(FakeOxRequest::get('/boom'));
        $h->pushRequest(FakeOxRequest::get('/ok'));

        $level = \ob_get_level();
        $kernel = new FakeSymfonyKernel(static function (Request $r): Response {
            if ($r->getPathInfo() === '/boom') {
                return new StreamedResponse(static function (): void {
                    echo 'partial';
                    \ob_start(); // user-opened buffer left unclosed at throw time
                    echo 'trapped';
                    throw new \RuntimeException('stream died');
                });
            }
            return new Response('sym:' . $r->getPathInfo());
        });

        $resets = 0;
        new HttpKernelRunner($kernel, new OxPHP(), userResetters: [static function () use (&$resets): void {
            $resets++;
        }])->run();

        $outputs = $h->capturedOutputs();
        self::assertCount(2, $outputs);
        self::assertStringContainsString('partial', $outputs[0]);
        self::assertStringNotContainsString('trapped', $outputs[0]);
        self::assertStringContainsString('Internal Server Error', $outputs[0]);
        self::assertSame('sym:/ok', $outputs[1]);
        self::assertSame(2, $resets);
        self::assertSame($level, \ob_get_level());
    }

    public function test_worker_passes_each_response_status_to_the_sapi(): void
    {
        $h = OxPHPHarness::instance();
        $h->setWorker(true);
        foreach (['/missing', '/redirect', '/teapot', '/stream', '/ok'] as $path) {
            $h->pushRequest(FakeOxRequest::get($path));
        }

        $kernel = new FakeSymfonyKernel(static fn (Request $r): Response => match ($r->getPathInfo()) {
            '/missing' => new Response('missing', 404),
            '/redirect' => new RedirectResponse('/ok', 302),
            '/teapot' => new Response('teapot', 418),
            '/stream' => new StreamedResponse(static function (): void {
                echo 'chunk';
            }, 201),
            default => new Response('ok', 200),
        });

        new HttpKernelRunner($kernel, new OxPHP(), userResetters: [])->run();

        self::assertSame([404, 302, 418, 201, 200], $h->capturedStatuses());
    }

    public function test_worker_loop_resets_per_request(): void
    {
        $h = OxPHPHarness::instance();
        $h->setWorker(true);
        $h->pushRequest(FakeOxRequest::get('/a'));
        $h->pushRequest(FakeOxRequest::get('/b'));

        $resets = 0;
        $runner = new HttpKernelRunner(
            FakeSymfonyKernel::echoingPath(),
            new OxPHP(),
            userResetters: [static function () use (&$resets): void {
                $resets++;
            }],
        );

        $runner->run();

        self::assertSame(['sym:/a', 'sym:/b'], $h->capturedOutputs());
        self::assertSame(2, $resets);
    }

    public function test_uploaded_file_is_keyed_by_its_form_field_name(): void
    {
        $files = $this->handleUpload($this->uploads->form());

        self::assertSame(['avatar', 'photos', 'user', 'deep', 'empty'], \array_keys($files));
        self::assertSame(['me.jpg', 'image/jpeg', 0, 'hello-avatar'], self::describe($files['avatar']));
    }

    public function test_array_field_keeps_every_file_in_order(): void
    {
        $files = $this->handleUpload($this->uploads->form());

        self::assertCount(2, $files['photos']);
        self::assertSame(['p1.jpg', 'image/jpeg', 0, 'photo-one'], self::describe($files['photos'][0]));
        self::assertSame(['p2.jpg', 'image/png', 0, 'photo-two'], self::describe($files['photos'][1]));
    }

    public function test_nested_fields_keep_their_structure(): void
    {
        $files = $this->handleUpload($this->uploads->form());

        self::assertSame(['me.jpg', 'image/jpeg', 0, 'nested-avatar'], self::describe($files['user']['avatar']));
        self::assertSame(['deep.txt', 'text/plain', 0, 'deep-file'], self::describe($files['deep']['a']['b']));
    }

    public function test_files_sharing_a_client_filename_are_both_kept(): void
    {
        $files = $this->handleUpload($this->uploads->form());

        self::assertSame('hello-avatar', self::describe($files['avatar'])[3]);
        self::assertSame('nested-avatar', self::describe($files['user']['avatar'])[3]);
    }

    public function test_file_input_left_empty_is_null_as_under_php_fpm(): void
    {
        $files = $this->handleUpload($this->uploads->form());

        self::assertArrayHasKey('empty', $files);
        self::assertNull($files['empty']);
    }

    public function test_worker_reads_the_uploads_of_each_request(): void
    {
        $h = OxPHPHarness::instance();
        $h->setWorker(true);
        $h->pushRequest(new FakeOxRequest(method: 'POST', path: '/a'), $this->uploads->single('doc', 'a.txt', 'first'));
        $h->pushRequest(new FakeOxRequest(method: 'POST', path: '/b'), $this->uploads->single('doc', 'b.txt', 'second'));

        $kernel = FakeSymfonyKernel::echoingPath();
        new HttpKernelRunner($kernel, new OxPHP(), userResetters: [])->run();

        self::assertSame(['a.txt', 'text/plain', 0, 'first'], self::describe($kernel->handled[0]->files->get('doc')));
        self::assertSame(['b.txt', 'text/plain', 0, 'second'], self::describe($kernel->handled[1]->files->get('doc')));
    }

    /**
     * @param array<string, mixed> $files the request's $_FILES
     * @return array<string, mixed> the built request's files
     */
    private function handleUpload(array $files): array
    {
        $_FILES = $files;
        OxPHPHarness::instance()->setCurrentRequest(new FakeOxRequest(method: 'POST', path: '/upload'));
        $kernel = FakeSymfonyKernel::echoingPath();

        \ob_start();
        new HttpKernelRunner($kernel, new OxPHP(), userResetters: [])->run();
        \ob_end_clean();

        return $kernel->handled[0]->files->all();
    }

    /** @return array{?string, ?string, int, string|false} client name, client type, error, contents */
    private static function describe(mixed $file): array
    {
        self::assertInstanceOf(UploadedFile::class, $file);

        return [$file->getClientOriginalName(), $file->getClientMimeType(), $file->getError(), \file_get_contents($file->getPathname())];
    }
}
