<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Symfony;

use OxPHP\Runtime\Internal\Fiber\RequestScopes;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RequestContext;

/**
 * @internal A `RequestContext` that keeps one stock context per request scope.
 *
 * Each scope starts from a clone of the configured template (base URL, host,
 * scheme and ports from the app's config), so one request's `fromRequest()` or
 * `setParameter('_locale', …)` never reaches another request's URL generation.
 * Every public instance method delegates to the caller's scope; the fluent ones
 * return this scoped context, not the inner one. `fromUri()` is static and
 * builds a fresh stock context, so it needs no override.
 *
 * Per-scope state is keyed by a private token object, not by `$this`, so
 * `clone $context` can mint a new token and give the clone a copy of the
 * current scope's context. Symfony clones the context (`UrlMatcher` runs
 * `(clone $context)->fromRequest($request)` on every match), and a clone must
 * start from the current state and never write back to the original.
 *
 * Outside worker mode there is a single shared scope, so this behaves exactly
 * like a stock `RequestContext` built from the template.
 */
final class FiberScopedRequestContext extends RequestContext
{
    /**
     * `RequestContext::__construct()` calls our overridden setters. While this
     * is true they write to a throwaway, so construction never leaves values
     * (the stock defaults) in whichever scope happens to be current.
     */
    private bool $constructing = true;

    private object $token;

    public function __construct(private readonly RequestContext $template)
    {
        $this->token = new \stdClass();

        parent::__construct();

        $this->constructing = false;
    }

    /**
     * The clone gets its own token and a copy of the current scope's context,
     * so changing one never changes the other.
     */
    public function __clone()
    {
        $inner = $this->inner();

        $this->token = new \stdClass();

        RequestScopes::current()->set($this->token, clone $inner);
    }

    public function fromRequest(Request $request): static
    {
        $this->inner()->fromRequest($request);

        return $this;
    }

    public function getBaseUrl(): string
    {
        return $this->inner()->getBaseUrl();
    }

    public function setBaseUrl(string $baseUrl): static
    {
        $this->inner()->setBaseUrl($baseUrl);

        return $this;
    }

    public function getPathInfo(): string
    {
        return $this->inner()->getPathInfo();
    }

    public function setPathInfo(string $pathInfo): static
    {
        $this->inner()->setPathInfo($pathInfo);

        return $this;
    }

    public function getMethod(): string
    {
        return $this->inner()->getMethod();
    }

    public function setMethod(string $method): static
    {
        $this->inner()->setMethod($method);

        return $this;
    }

    public function getHost(): string
    {
        return $this->inner()->getHost();
    }

    public function setHost(string $host): static
    {
        $this->inner()->setHost($host);

        return $this;
    }

    public function getScheme(): string
    {
        return $this->inner()->getScheme();
    }

    public function setScheme(string $scheme): static
    {
        $this->inner()->setScheme($scheme);

        return $this;
    }

    public function getHttpPort(): int
    {
        return $this->inner()->getHttpPort();
    }

    public function setHttpPort(int $httpPort): static
    {
        $this->inner()->setHttpPort($httpPort);

        return $this;
    }

    public function getHttpsPort(): int
    {
        return $this->inner()->getHttpsPort();
    }

    public function setHttpsPort(int $httpsPort): static
    {
        $this->inner()->setHttpsPort($httpsPort);

        return $this;
    }

    public function getQueryString(): string
    {
        return $this->inner()->getQueryString();
    }

    public function setQueryString(?string $queryString): static
    {
        $this->inner()->setQueryString($queryString);

        return $this;
    }

    public function getParameters(): array
    {
        return $this->inner()->getParameters();
    }

    public function setParameters(array $parameters): static
    {
        $this->inner()->setParameters($parameters);

        return $this;
    }

    public function getParameter(string $name): mixed
    {
        return $this->inner()->getParameter($name);
    }

    public function hasParameter(string $name): bool
    {
        return $this->inner()->hasParameter($name);
    }

    public function setParameter(string $name, mixed $parameter): static
    {
        $this->inner()->setParameter($name, $parameter);

        return $this;
    }

    public function isSecure(): bool
    {
        return $this->inner()->isSecure();
    }

    private function inner(): RequestContext
    {
        if ($this->constructing) {
            return new RequestContext();
        }

        $inner = RequestScopes::current()->get($this->token, fn (): RequestContext => clone $this->template);
        \assert($inner instanceof RequestContext);

        return $inner;
    }
}
