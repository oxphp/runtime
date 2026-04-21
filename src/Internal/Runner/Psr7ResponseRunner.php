<?php
declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Runner;

use OxPHP\Runtime\Internal\Bridge\OxPHP;
use OxPHP\Runtime\Internal\Emitter\Psr17FactoryLocator;
use OxPHP\Runtime\Internal\Emitter\ResponseEmitter;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Runtime\RunnerInterface;

/**
 * @internal Emits a pre-built PSR-7 Response. One-shot — never enters the
 * worker loop; returning a Response literal from index.php only makes
 * sense for trivial endpoints (e.g. a health check stub), and looping
 * with the same response object for every request has no use case.
 */
final class Psr7ResponseRunner implements RunnerInterface
{
    private readonly ResponseEmitter $emitter;

    public function __construct(
        private readonly ResponseInterface $response,
        OxPHP $bridge,
        private readonly Psr17FactoryLocator $psr17, // kept for API symmetry; not used today
    ) {
        $this->emitter = new ResponseEmitter($bridge);
    }

    public function run(): int
    {
        $this->emitter->emit(
            $this->response->getStatusCode(),
            $this->response->getHeaders(),
            $this->streamBody(),
        );
        return 0;
    }

    /** @return \Generator<string> */
    private function streamBody(): \Generator
    {
        $body = $this->response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        while (!$body->eof()) {
            yield $body->read(8192);
        }
    }
}
