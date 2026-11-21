<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub\OverrideCheck;

/**
 * Parent for OverrideCheckTest: two public instance methods, one static, one constructor.
 */
class ParentFixture
{
    public function __construct() {}

    public static function c(): void {}

    public function a(): void {}

    public function b(): void {}
}
