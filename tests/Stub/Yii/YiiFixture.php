<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub\Yii;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Log\NullLogger;
use Yiisoft\Definitions\DynamicReference;
use Yiisoft\Definitions\Reference;
use Yiisoft\ErrorHandler\ErrorHandler;
use Yiisoft\ErrorHandler\Renderer\PlainTextRenderer;
use Yiisoft\ErrorHandler\ThrowableResponseFactoryInterface;
use Yiisoft\Middleware\Dispatcher\MiddlewareDispatcher;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Yii\Http\Application;

/**
 * A minimal Yii web application as config groups: `Application` routes with
 * `RouteProbeMiddleware` and answers with `RouteEchoHandler`. It has no
 * `ErrorCatcher` in its stack, so a throw escapes `Application::handle()`.
 */
final class YiiFixture
{
    private static bool $failed = false;

    public static function reset(): void
    {
        self::$failed = false;
    }

    /**
     * @param array<array-key, mixed> $di merged over the base definitions
     * @param list<callable> $bootstrap
     * @param array<array-key, mixed> $events
     */
    public static function config(string $suffix = 'web', array $di = [], array $bootstrap = [], array $events = []): ArrayConfig
    {
        return new ArrayConfig([
            "di-{$suffix}" => [...self::definitions(), ...$di],
            "di-providers-{$suffix}" => [],
            "di-delegates-{$suffix}" => [],
            "di-tags-{$suffix}" => [],
            "bootstrap-{$suffix}" => $bootstrap,
            "events-{$suffix}" => $events,
            "params-{$suffix}" => [],
        ]);
    }

    /**
     * A bootstrap callback that marks the request's `PerRequestProbe` and logs `Bootstrap`.
     */
    public static function markBootstrapped(): \Closure
    {
        return static function (ContainerInterface $c): void {
            $c->get(PerRequestProbe::class)->bootstrapped = true;
            EventLog::$events[] = 'Bootstrap';
        };
    }

    /**
     * A bootstrap callback that throws the first time it runs after `reset()`.
     */
    public static function failingOnce(): \Closure
    {
        return static function (): void {
            if (!self::$failed) {
                self::$failed = true;

                throw new \RuntimeException('setup boom');
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function definitions(): array
    {
        return [
            ServerRequestFactoryInterface::class => Psr17Factory::class,
            StreamFactoryInterface::class => Psr17Factory::class,
            UploadedFileFactoryInterface::class => Psr17Factory::class,
            UriFactoryInterface::class => Psr17Factory::class,
            ResponseFactoryInterface::class => Psr17Factory::class,
            EventDispatcherInterface::class => EventLog::class,
            ThrowableResponseFactoryInterface::class => FakeThrowableResponseFactory::class,
            ErrorHandler::class => static fn (): ErrorHandler => new ErrorHandler(new NullLogger(), new PlainTextRenderer()),
            CurrentRoute::class => [
                'class' => CurrentRoute::class,
                'reset' => function (): void {
                    $this->route = null;
                    $this->uri = null;
                    $this->arguments = [];
                },
            ],
            Application::class => [
                '__construct()' => [
                    'dispatcher' => DynamicReference::to([
                        'class' => MiddlewareDispatcher::class,
                        'withMiddlewares()' => [[RouteProbeMiddleware::class]],
                    ]),
                    'fallbackHandler' => Reference::to(RouteEchoHandler::class),
                ],
            ],
        ];
    }
}
