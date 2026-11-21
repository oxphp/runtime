<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub;

use Symfony\Component\Routing\RequestContext;

/**
 * An app's own request context, with state of its own the scoped decorator
 * would not carry.
 */
final class TenantRequestContext extends RequestContext
{
    public string $tenant = '';
}
