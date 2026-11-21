<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Symfony;

/**
 * @internal Reflection helpers that guard the scoped subclasses against Symfony
 * growing a new public method.
 *
 * A subclass method nobody overrode would fall through to the parent's own
 * private, empty state and silently bypass the per-request scope.
 */
final class OverrideCheck
{
    /**
     * Names of the public, non-static methods of `$parent` (`__construct`
     * excluded) that `$subclass` does not declare itself.
     *
     * @param class-string $subclass
     * @param class-string $parent
     * @param list<string> $exempt   Methods that keep no per-request state
     *
     * @return list<string>
     */
    public static function missing(string $subclass, string $parent, array $exempt = []): array
    {
        $subclassName = new \ReflectionClass($subclass)->getName();
        $missing = [];

        foreach (new \ReflectionClass($parent)->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();

            if ($method->isStatic() || $name === '__construct' || \in_array($name, $exempt, true)) {
                continue;
            }

            if (new \ReflectionMethod($subclass, $name)->getDeclaringClass()->getName() !== $subclassName) {
                $missing[] = $name;
            }
        }

        return $missing;
    }
}
