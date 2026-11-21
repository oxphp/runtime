<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Fiber;

/**
 * @internal Holds per-request state for scoped services, keyed by the service
 * instance that owns it.
 *
 * A rebuilt container brings new service instances, which therefore start with
 * no state in any scope.
 */
final class RequestScope
{
    /** @var \WeakMap<object, mixed> */
    private \WeakMap $values;

    public function __construct()
    {
        $this->values = new \WeakMap();
    }

    /**
     * Returns the value stored for `$owner`, storing `$initial()` on first access.
     *
     * A stored `null` counts as stored, so `$initial` runs at most once per owner.
     *
     * @param \Closure(): mixed $initial
     */
    public function get(object $owner, \Closure $initial): mixed
    {
        if (!$this->values->offsetExists($owner)) {
            $this->values[$owner] = $initial();
        }

        return $this->values[$owner];
    }

    public function set(object $owner, mixed $value): void
    {
        $this->values[$owner] = $value;
    }
}
