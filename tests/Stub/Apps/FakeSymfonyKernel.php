<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub\Apps;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

final class FakeSymfonyKernel implements HttpKernelInterface, TerminableInterface
{
    /** @var list<Request> */
    public array $handled = [];
    /** @var list<array{Request, Response}> */
    public array $terminated = [];

    public function __construct(private readonly \Closure $responder) {}

    public static function echoingPath(): self
    {
        return new self(static function (Request $r): Response {
            return new Response('sym:' . $r->getPathInfo(), 200, ['X-Kernel' => 'fake']);
        });
    }

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        $this->handled[] = $request;
        return ($this->responder)($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->terminated[] = [$request, $response];
    }
}
