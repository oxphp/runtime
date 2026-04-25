<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use OxPHP\Runtime\Tests\Stub\OxPHPHarness;

// Declare OxPHP\Http interface polyfills only when the real oxphp extension isn't loaded.
// Method signatures mirror oxphp.stub.php — the single source of truth for the extension API.
if (!\interface_exists(\OxPHP\Http\RequestInterface::class, false)) {
    require __DIR__ . '/polyfill/oxphp_http_interfaces.php';
}

// Declare stubs only when the real oxphp extension isn't loaded.
if (!\function_exists('oxphp_http_request')) {
    function oxphp_http_request(): \OxPHP\Http\RequestInterface
    {
        return OxPHPHarness::instance()->request();
    }
    function oxphp_server_info(): array
    {
        return OxPHPHarness::instance()->serverInfo();
    }
    function oxphp_is_worker(): bool
    {
        return OxPHPHarness::instance()->isWorker();
    }
    function oxphp_is_streaming(): bool
    {
        return OxPHPHarness::instance()->isStreaming();
    }
    function oxphp_stream_flush(): bool
    {
        return OxPHPHarness::instance()->recordFlush();
    }
    function oxphp_finish_request(): bool
    {
        return OxPHPHarness::instance()->recordFinish();
    }
    function oxphp_request_id(): string
    {
        return OxPHPHarness::instance()->requestId();
    }
    function oxphp_worker_id(): int
    {
        return OxPHPHarness::instance()->workerId();
    }
    function oxphp_worker(callable $h): bool
    {
        return OxPHPHarness::instance()->driveWorker($h);
    }
}
