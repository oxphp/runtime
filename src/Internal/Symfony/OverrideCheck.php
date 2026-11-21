<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Symfony;

/**
 * @internal Reflection helpers that guard the scoped subclasses against Symfony
 * growing a new public method.
 *
 * A subclass method nobody overrode would fall through to the parent's own
 * private, empty state and silently bypass the per-request scope. A constructor
 * that no longer takes what the service definition passes would drop an argument
 * or fail when the container builds the service.
 */
final class OverrideCheck
{
    /**
     * Names of the public, non-static methods of `$parent` (`__construct`
     * excluded) that `$subclass` does not declare itself. `$subclass` need not
     * extend `$parent`: a decorator of a final class is checked the same way,
     * and a method it lacks altogether counts as missing.
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

            if (!\method_exists($subclass, $name) || new \ReflectionMethod($subclass, $name)->getDeclaringClass()->getName() !== $subclassName) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /**
     * How the constructor of `$subclass` differs from the one of `$parent`, which
     * the service definition's arguments were written for, by position or by
     * name: `setClass()` keeps them. Each stock parameter must be at the same
     * position under the same name, with the same type, variadic or not, and
     * optional when the stock one is; parameters after them must be optional.
     * A class without a constructor takes no parameters.
     *
     * @param class-string $subclass
     * @param class-string $parent
     *
     * @return list<string> one line per difference, empty when there is none
     */
    public static function constructorDifferences(string $subclass, string $parent): array
    {
        $stock = new \ReflectionClass($parent)->getConstructor()?->getParameters() ?? [];
        $own = new \ReflectionClass($subclass)->getConstructor()?->getParameters() ?? [];
        $differences = [];

        foreach ($stock as $position => $expected) {
            $actual = $own[$position] ?? null;

            if ($actual === null) {
                $differences[] = \sprintf('has no $%s', $expected->getName());
            } elseif (self::signature($actual) !== self::signature($expected)) {
                $differences[] = \sprintf('takes %s where the stock one takes %s', self::signature($actual), self::signature($expected));
            } elseif ($expected->isOptional() && !$actual->isOptional()) {
                $differences[] = \sprintf('requires $%s, which the stock one makes optional', $actual->getName());
            }
        }

        foreach (\array_slice($own, \count($stock)) as $added) {
            if (!$added->isOptional()) {
                $differences[] = \sprintf('requires $%s, which the stock one has no parameter for', $added->getName());
            }
        }

        return $differences;
    }

    /** The parameter's type, `...` and name, as declared */
    private static function signature(\ReflectionParameter $parameter): string
    {
        $type = (string) $parameter->getType();

        return ($type === '' ? '' : $type . ' ') . ($parameter->isVariadic() ? '...$' : '$') . $parameter->getName();
    }
}
