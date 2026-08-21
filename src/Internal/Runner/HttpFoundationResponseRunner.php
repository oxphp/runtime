<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Runner;

use OxPHP\Runtime\Internal\Bridge\OxPHP;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Runtime\RunnerInterface;

/**
 * @internal Emits a pre-built HttpFoundation Response (or StreamedResponse).
 * One-shot — the runner is not meant for worker-loop use; returning a
 * Response literal from index.php is a degenerate case for trivial scripts.
 */
final class HttpFoundationResponseRunner implements RunnerInterface
{
    private readonly HttpFoundationResponseEmitter $emitter;

    public function __construct(
        private readonly Response $response,
        OxPHP $bridge,
    ) {
        $this->emitter = new HttpFoundationResponseEmitter($bridge);
    }

    public function run(): int
    {
        $this->emitter->emit($this->response);
        return 0;
    }
}
