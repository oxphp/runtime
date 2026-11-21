<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub\Yii;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\Route;

/**
 * Plays the router: sets the route named by the `route` query param on the
 * container's `CurrentRoute`, which throws when a route is already set. With
 * `throw` it throws that message instead; with `suspend` it pauses its fiber after
 * routing, so another request can run in between.
 */
final class RouteProbeMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly CurrentRoute $currentRoute) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $q = $request->getQueryParams();

        if (isset($q['throw'])) {
            throw new \RuntimeException($q['throw']);
        }

        $this->currentRoute->setRouteWithArguments(
            Route::get('/' . $q['route'])->name($q['route']),
            ['id' => $q['id'] ?? ''],
        );

        if (isset($q['suspend'])) {
            \Fiber::suspend();
        }

        return $handler->handle($request);
    }
}
