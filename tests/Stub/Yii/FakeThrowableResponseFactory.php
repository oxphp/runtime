<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub\Yii;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\ErrorHandler\ThrowableResponseFactoryInterface;

/**
 * Renders a throwable as a plain 500, and fails itself on the message "fatal", so
 * a test can make `ErrorCatcher` throw too.
 */
final class FakeThrowableResponseFactory implements ThrowableResponseFactoryInterface
{
    public function __construct(private readonly ResponseFactoryInterface $responses) {}

    public function create(\Throwable $throwable, ServerRequestInterface $request): ResponseInterface
    {
        if ($throwable->getMessage() === 'fatal') {
            throw new \LogicException('catcher boom');
        }

        $response = $this->responses->createResponse(500);
        $response->getBody()->write('caught: ' . $throwable->getMessage());

        return $response;
    }
}
