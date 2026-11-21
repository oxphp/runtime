<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub\Yii;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * The application's fallback handler. With `size` it returns that many bytes, with
 * `status` that status; otherwise a JSON report of what this request saw: its route,
 * which probe instances it got, its start time and whether bootstrap ran.
 */
final class RouteEchoHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly CurrentRoute $currentRoute,
        private readonly PerRequestProbe $probe,
        private readonly SharedProbe $shared,
        private readonly ResponseFactoryInterface $responses,
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

        return $this->respond(200, \json_encode([
            'route' => $this->currentRoute->getName(),
            'id' => $this->currentRoute->getArgument('id'),
            'probe' => \spl_object_id($this->probe),
            'shared' => \spl_object_id($this->shared),
            'start' => $request->getAttribute('applicationStartTime'),
            'bootstrapped' => $this->probe->bootstrapped,
        ], \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION));
    }

    private function respond(int $status, string $body): ResponseInterface
    {
        $response = $this->responses->createResponse($status);
        $response->getBody()->write($body);

        return $response;
    }
}
