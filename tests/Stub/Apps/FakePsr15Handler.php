<?php
declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub\Apps;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class FakePsr15Handler implements RequestHandlerInterface
{
    /** @var list<ServerRequestInterface> */
    public array $received = [];

    public function __construct(private readonly \Closure $responder) {}

    public static function echoingPath(): self
    {
        $f = new Psr17Factory();
        return new self(static function (ServerRequestInterface $req) use ($f): ResponseInterface {
            return $f
                ->createResponse(200)
                ->withHeader('Content-Type', 'text/plain')
                ->withBody($f->createStream('psr15:'.$req->getUri()->getPath()));
        });
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->received[] = $request;
        return ($this->responder)($request);
    }
}
