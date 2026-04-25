<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Reset;

use OxPHP\Runtime\Resetter\ResetterInterface;

/**
 * @internal Passive iterator over a list of resetters.
 *
 * Exceptions thrown by a resetter are caught and reported via error_log(),
 * so a faulty resetter cannot prevent subsequent ones from running — the
 * worker must always reach a clean state between requests.
 */
final class ResetterChain
{
    /** @param iterable<ResetterInterface|callable> $resetters */
    public function __construct(private readonly iterable $resetters) {}

    public function reset(): void
    {
        foreach ($this->resetters as $r) {
            try {
                if ($r instanceof ResetterInterface) {
                    $r->reset();
                } else {
                    $r();
                }
            } catch (\Throwable $e) {
                \error_log('OxPHP resetter failed: ' . $e);
            }
        }
    }
}
