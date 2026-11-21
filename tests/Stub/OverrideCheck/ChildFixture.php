<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub\OverrideCheck;

/**
 * Declares `a()` itself and inherits `b()` untouched.
 */
final class ChildFixture extends ParentFixture
{
    public function a(): void {}
}
