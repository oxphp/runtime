<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub\Apps;

use Illuminate\Contracts\Http\Kernel as LaravelKernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class FakeLaravelKernel implements LaravelKernel
{
    /** @var list<Request> */
    public array $handled = [];
    public int $terminated = 0;

    public function __construct(private readonly \Closure $responder) {}

    public static function echoingPath(): self
    {
        return new self(static function (Request $r): Response {
            return new Response('lar:' . $r->getPathInfo(), 200, ['X-Kernel' => 'laravel']);
        });
    }

    public function bootstrap(): void {}

    public function handle($request)
    {
        $this->handled[] = $request;
        return ($this->responder)($request);
    }

    public function terminate($request, $response): void
    {
        $this->terminated++;
    }

    public function getApplication(): void
    {
        throw new \LogicException('not needed for tests');
    }
}
