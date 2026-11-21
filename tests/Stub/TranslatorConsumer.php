<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub;

/**
 * Stands in for FrameworkBundle's `TranslationDebugCommand`: holds whatever translator argument 0 resolves to.
 */
final class TranslatorConsumer
{
    public function __construct(public readonly object $translator) {}
}
