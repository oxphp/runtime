<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Yii;

/**
 * @internal Thrown by the shared container's stand-in for a per-request service,
 * so a shared service that depends on one fails at boot instead of capturing it.
 */
final class PerRequestServiceRequested extends \LogicException
{
    public function __construct(public readonly string $id)
    {
        parent::__construct(\sprintf('Per-request service "%s" was requested from the shared container.', $id));
    }
}
