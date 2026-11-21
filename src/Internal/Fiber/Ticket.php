<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Fiber;

/**
 * @internal One request, as the runner opens and closes it through `RequestScopes`.
 *
 * Opaque: it carries no state of its own. The registry counts the tickets it
 * entered and has not closed yet, so a ticket is open exactly while the
 * registry holds it, and closes at most once.
 *
 * The runner creates it first, registers the request's shutdown close with it,
 * and only then enters it: a request ended before its ticket was entered (a time
 * limit, a cancel) is never counted, and one ended after is always closed.
 */
final class Ticket {}
