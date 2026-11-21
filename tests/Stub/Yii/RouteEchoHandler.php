<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub\Yii;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Injector\Injector;
use Yiisoft\Router\CurrentRoute;

/**
 * The application's fallback handler. With `size` it returns that many bytes, with
 * `status` that status. With `render` it returns a body rendered when it is first read,
 * through the request's `Injector`, as a Yii view renderer renders a view, so the
 * response holds the request container; with `render_throw`, such a body whose
 * rendering throws that message. Otherwise it returns a JSON report of what this
 * request saw: its route, which probe instances it got, its start time and whether
 * bootstrap ran. With `cache_control`, the report carries that `Cache-Control` header.
 */
final class RouteEchoHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly CurrentRoute $currentRoute,
        private readonly PerRequestProbe $probe,
        private readonly SharedProbe $shared,
        private readonly ResponseFactoryInterface $responses,
        private readonly Injector $injector,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();

        if (isset($q['size'])) {
            return $this->respond(200, \str_repeat('x', (int) $q['size']));
        }

        if (isset($q['status'])) {
            return $this->respond((int) $q['status'], 'status-body');
        }

        if (isset($q['render']) || isset($q['render_throw'])) {
            $failure = isset($q['render_throw']) ? (string) $q['render_throw'] : null;

            return new DeferredBodyResponse(
                $this->responses->createResponse(),
                fn (): StreamInterface => $this->render($failure),
            );
        }

        $response = $this->respond(200, \json_encode([
            'route' => $this->currentRoute->getName(),
            'id' => $this->currentRoute->getArgument('id'),
            'probe' => \spl_object_id($this->probe),
            'shared' => \spl_object_id($this->shared),
            'start' => $request->getAttribute('applicationStartTime'),
            'bootstrapped' => $this->probe->bootstrapped,
        ], \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION));

        return isset($q['cache_control'])
            ? $response->withHeader('Cache-Control', (string) $q['cache_control'])
            : $response;
    }

    private function render(?string $failure): StreamInterface
    {
        $html = $this->injector->invoke(static function (CurrentRoute $route) use ($failure): string {
            if ($failure !== null) {
                throw new \RuntimeException($failure);
            }

            return '<h1>' . $route->getName() . '</h1>';
        });

        $body = $this->responses->createResponse()->getBody();
        $body->write($html);

        return $body;
    }

    private function respond(int $status, string $body): ResponseInterface
    {
        $response = $this->responses->createResponse($status);
        $response->getBody()->write($body);

        return $response;
    }
}
