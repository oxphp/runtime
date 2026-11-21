<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub\Yii;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Records the short class name of every dispatched event, across every container
 * that builds one, and can make one event's listener throw.
 */
final class EventLog implements EventDispatcherInterface
{
    /** @var list<string> */
    public static array $events = [];

    /** The short class name of the event whose dispatch throws, if any. */
    public static ?string $throwOn = null;

    public static function reset(): void
    {
        self::$events = [];
        self::$throwOn = null;
    }

    public function dispatch(object $event): object
    {
        $name = new \ReflectionClass($event)->getShortName();
        self::$events[] = $name;

        if ($name === self::$throwOn) {
            throw new \RuntimeException('listener boom');
        }

        return $event;
    }
}
