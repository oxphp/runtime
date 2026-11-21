<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub;

use OxPHP\Runtime\Internal\Fiber\RequestScopes;

/**
 * Plays the part of an OxPHP request fiber: opens a request scope on entry and
 * closes it when the body ends. Bodies pause with `\Fiber::suspend()`.
 */
final class RequestFiber
{
    private function __construct(private readonly \Fiber $fiber) {}

    public static function start(\Closure $body): self
    {
        $fiber = new \Fiber(static function () use ($body): mixed {
            $ticket = RequestScopes::enterRequest();

            try {
                return $body();
            } finally {
                RequestScopes::leaveRequest($ticket);
            }
        });

        $fiber->start();

        return new self($fiber);
    }

    public function resume(): self
    {
        $this->fiber->resume();

        return $this;
    }

    public function isTerminated(): bool
    {
        return $this->fiber->isTerminated();
    }

    public function result(): mixed
    {
        return $this->fiber->getReturn();
    }
}
