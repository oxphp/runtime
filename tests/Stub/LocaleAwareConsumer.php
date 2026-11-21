<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub;

/**
 * Receives a tagged iterator, like `translation.locale_switcher` does, and exposes it.
 */
final class LocaleAwareConsumer
{
    public function __construct(public readonly iterable $services) {}
}
