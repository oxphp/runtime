<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Yii;

use Psr\Container\ContainerInterface;

/**
 * @internal Finds a PSR-11 container or a `Yiisoft\Injector\Injector` reachable
 * from an object graph. A service shared across requests that keeps one would
 * resolve services from the boot container later on and leak them between
 * overlapping requests.
 *
 * The path of a hit is built from these segments, and the root itself is `''`:
 * `->name` (property), `[0]` / `['key']` (array element), `{this}` (a closure's
 * bound object), `{use $name}` (a closure's `use` variable), `[#n]` / `[#n data]`
 * (the n-th object of an `SplObjectStorage` or `WeakMap`, and the data attached
 * to it).
 *
 * Not seen by the walk: `SplDoublyLinkedList`/`SplQueue`/`SplStack`,
 * `SplHeap`/`SplPriorityQueue`, `SplFixedArray`, generators, and the native state
 * of internal objects.
 */
final class ContainerReferenceFinder
{
    // Compared by name: yiisoft/injector may not be installed.
    private const INJECTOR = 'Yiisoft\Injector\Injector';

    /**
     * @return array{path: string, class: class-string}|null
     */
    public static function find(mixed $root): ?array
    {
        /** @var list<array{string, mixed}> $stack */
        $stack = [['', $root]];
        /** @var array<int, true> $seen */
        $seen = [];
        // Arrays have no identity, so an array that holds a reference to itself is
        // told apart by the id of that reference.
        /** @var array<string, true> $seenReferences */
        $seenReferences = [];

        while ($stack !== []) {
            [$path, $value] = \array_pop($stack);

            if (\is_array($value)) {
                $children = self::arrayChildren($path, $value, $seenReferences);
            } elseif (\is_object($value)) {
                if ($value instanceof ContainerInterface || \is_a($value, self::INJECTOR)) {
                    return ['path' => $path, 'class' => $value::class];
                }

                $id = \spl_object_id($value);
                if (isset($seen[$id]) || $value instanceof \UnitEnum) {
                    continue;
                }
                $seen[$id] = true;

                // Reading the properties of a lazy object would initialize it.
                if (new \ReflectionClass($value)->isUninitializedLazyObject($value)) {
                    continue;
                }

                $children = self::objectChildren($path, $value, $seenReferences);
            } else {
                continue;
            }

            // Pushed in reverse, so the first child is the first one visited.
            for ($i = \count($children) - 1; $i >= 0; --$i) {
                $stack[] = $children[$i];
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $array
     * @param array<string, true>     $seenReferences ids of the references already queued
     *
     * @return list<array{string, mixed}>
     */
    private static function arrayChildren(string $path, array $array, array &$seenReferences): array
    {
        $children = [];
        foreach ($array as $key => $value) {
            $reference = \ReflectionReference::fromArrayElement($array, $key);
            if ($reference !== null) {
                $id = $reference->getId();
                if (isset($seenReferences[$id])) {
                    continue;
                }
                $seenReferences[$id] = true;
            }

            $children[] = [$path . (\is_int($key) ? '[' . $key . ']' : "['" . $key . "']"), $value];
        }

        return $children;
    }

    /**
     * @param array<string, true> $seenReferences
     *
     * @return list<array{string, mixed}>
     */
    private static function objectChildren(string $path, object $object, array &$seenReferences): array
    {
        $children = [];

        if ($object instanceof \Closure) {
            $reflection = new \ReflectionFunction($object);
            $bound = $reflection->getClosureThis();
            if ($bound !== null) {
                $children[] = [$path . '{this}', $bound];
            }
            foreach ($reflection->getClosureUsedVariables() as $name => $value) {
                $children[] = [$path . '{use $' . $name . '}', $value];
            }

            return $children;
        }

        foreach (\get_mangled_object_vars($object) as $name => $value) {
            $name = (string) $name;
            $separator = \strrpos($name, "\0");
            $children[] = [$path . '->' . ($separator === false ? $name : \substr($name, $separator + 1)), $value];
        }

        if ($object instanceof \ArrayObject || $object instanceof \ArrayIterator) {
            // Their elements aren't properties.
            $children = [...$children, ...self::arrayChildren($path, $object->getArrayCopy(), $seenReferences)];
        } elseif ($object instanceof \SplObjectStorage) {
            $n = 0;
            foreach ($object as $entry) {
                $children[] = [$path . '[#' . $n . ']', $entry];
                $children[] = [$path . '[#' . $n . ' data]', $object[$entry]];
                ++$n;
            }
        } elseif ($object instanceof \WeakMap) {
            $n = 0;
            foreach ($object as $entry => $data) {
                $children[] = [$path . '[#' . $n . ']', $entry];
                $children[] = [$path . '[#' . $n . ' data]', $data];
                ++$n;
            }
        }

        return $children;
    }
}
