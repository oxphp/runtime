<?php
declare(strict_types=1);

namespace OxPHP\Runtime\Resetter;

/**
 * Implement this in user code to reset framework-scoped state between
 * requests in worker mode (e.g. Doctrine EntityManager, custom container
 * bindings, session handlers).
 *
 * Registered via the `resetters` runtime option.
 */
interface ResetterInterface
{
    public function reset(): void;
}
