<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Runner;

use OxPHP\Runtime\Internal\Bridge\OxPHP;
use OxPHP\Runtime\Internal\Runner\LaravelRunner;
use OxPHP\Runtime\Tests\Stub\Apps\FakeLaravelKernel;
use OxPHP\Runtime\Tests\Stub\FakeOxRequest;
use OxPHP\Runtime\Tests\Stub\OxPHPHarness;
use OxPHP\Runtime\Tests\Stub\UploadedFilesFixture;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

// illuminate/* are dev dependencies; runs against other Symfony versions may drop them.
#[RequiresMethod(\Illuminate\Contracts\Http\Kernel::class, 'handle')]
#[RequiresMethod(\Illuminate\Http\Request::class, 'createFromBase')]
final class LaravelRunnerTest extends TestCase
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

    public function test_handle_and_terminate_invoked_in_order(): void
    {
        OxPHPHarness::instance()->setCurrentRequest(FakeOxRequest::get('/users/1'));

        $kernel = FakeLaravelKernel::echoingPath();
        $runner = new LaravelRunner($kernel, new OxPHP(), userResetters: []);

        \ob_start();
        $runner->run();
        $out = (string) \ob_get_clean();

        self::assertSame('lar:/users/1', $out);
        self::assertCount(1, $kernel->handled);
        self::assertSame(1, $kernel->terminated);
    }

    public function test_request_carries_server_software(): void
    {
        OxPHPHarness::instance()->setCurrentRequest(FakeOxRequest::get('/'));
        $kernel = FakeLaravelKernel::echoingPath();

        \ob_start();
        new LaravelRunner($kernel, new OxPHP(), userResetters: [])->run();
        \ob_end_clean();

        self::assertSame('OxPHP/0.0.0-test', $kernel->handled[0]->server->get('SERVER_SOFTWARE'));
    }

    public function test_streamed_response_interleaves_emission_with_callback(): void
    {
        OxPHPHarness::instance()->setCurrentRequest(FakeOxRequest::get('/sse'));

        $events = [];
        $kernel = new FakeLaravelKernel(static function () use (&$events): StreamedResponse {
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
        new LaravelRunner($kernel, new OxPHP(), userResetters: [])->run();
        \ob_end_flush();
        $out = (string) \ob_get_clean();

        self::assertSame('onetwo', $out);
        self::assertSame(
            ['wire:one', 'cb:one flushes=0', 'wire:two', 'cb:two flushes=0'],
            $events,
        );
        self::assertSame(1, OxPHPHarness::instance()->flushCount());
        self::assertSame(1, $kernel->terminated);
    }

    public function test_worker_loop_runs_terminate_per_request_and_resets(): void
    {
        $h = OxPHPHarness::instance();
        $h->setWorker(true);
        $h->pushRequest(FakeOxRequest::get('/a'));
        $h->pushRequest(FakeOxRequest::get('/b'));

        $resets = 0;
        $kernel = FakeLaravelKernel::echoingPath();
        $runner = new LaravelRunner(
            $kernel,
            new OxPHP(),
            userResetters: [static function () use (&$resets): void {
                $resets++;
            }],
        );

        $runner->run();

        self::assertSame(['lar:/a', 'lar:/b'], $h->capturedOutputs());
        self::assertSame(2, $kernel->terminated);
        self::assertSame(2, $resets);
    }

    public function test_uploaded_files_are_found_by_their_form_field_names(): void
    {
        $_FILES = $this->uploads->form();
        OxPHPHarness::instance()->setCurrentRequest(new FakeOxRequest(method: 'POST', path: '/upload'));
        $kernel = FakeLaravelKernel::echoingPath();

        \ob_start();
        new LaravelRunner($kernel, new OxPHP(), userResetters: [])->run();
        \ob_end_clean();

        $request = $kernel->handled[0];
        self::assertSame('hello-avatar', \file_get_contents($request->file('avatar')->getPathname()));
        self::assertSame('me.jpg', $request->file('avatar')->getClientOriginalName());
        self::assertSame('photo-two', \file_get_contents($request->file('photos')[1]->getPathname()));
        self::assertSame('nested-avatar', \file_get_contents($request->file('user.avatar')->getPathname()));
        self::assertSame('deep-file', \file_get_contents($request->file('deep.a.b')->getPathname()));
        self::assertFalse($request->hasFile('empty'));
    }
}
