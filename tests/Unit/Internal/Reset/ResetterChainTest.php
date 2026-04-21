<?php
declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Reset;

use OxPHP\Runtime\Internal\Reset\ResetterChain;
use OxPHP\Runtime\Resetter\ResetterInterface;
use PHPUnit\Framework\TestCase;

final class ResetterChainTest extends TestCase
{
    public function test_invokes_resetter_interface_and_callable_in_given_order(): void
    {
        $calls = [];

        $r1 = new class($calls) implements ResetterInterface {
            public function __construct(private array &$calls) {}
            public function reset(): void { $this->calls[] = 'r1'; }
        };
        $r2 = static function () use (&$calls): void { $calls[] = 'r2'; };

        (new ResetterChain([$r1, $r2]))->reset();

        self::assertSame(['r1', 'r2'], $calls);
    }

    public function test_exception_in_one_resetter_does_not_skip_later_ones(): void
    {
        $calls = [];
        $chain = new ResetterChain([
            static function () use (&$calls): void { $calls[] = 'before'; throw new \RuntimeException('boom'); },
            static function () use (&$calls): void { $calls[] = 'after'; },
        ]);

        try { $chain->reset(); } catch (\RuntimeException) { /* aggregated below */ }

        // Design choice: per spec section 8 each resetter must run; exceptions
        // are logged but not re-thrown, so the chain always completes.
        self::assertSame(['before', 'after'], $calls);
    }

    public function test_empty_chain_is_noop(): void
    {
        (new ResetterChain([]))->reset();
        self::assertTrue(true);
    }
}
