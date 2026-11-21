<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Yii;

use OxPHP\Runtime\Internal\Yii\ContainerReferenceFinder;
use OxPHP\Runtime\Tests\Stub\Yii\Guard\LazyService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yiisoft\Di\Container;
use Yiisoft\Injector\Injector;

final class ContainerReferenceFinderTest extends TestCase
{
    public function test_a_plain_graph_holds_no_container(): void
    {
        self::assertNull(ContainerReferenceFinder::find(new class () {
            public array $a = [1, 'x'];
            public ?object $o = null;
        }));
    }

    public function test_finds_a_container_in_a_property(): void
    {
        self::assertSame(
            ['path' => '->holder', 'class' => Container::class],
            ContainerReferenceFinder::find(new class (new Container()) {
                public function __construct(private readonly Container $holder) {}
            }),
        );
    }

    public function test_finds_a_container_in_a_parent_private_property_through_nesting(): void
    {
        $inner = new class (new Container()) extends ContainerOwner {};

        self::assertSame(
            ['path' => '->inner->container', 'class' => Container::class],
            ContainerReferenceFinder::find(new class ($inner) {
                public function __construct(public readonly object $inner) {}
            }),
        );
    }

    public function test_finds_a_container_in_an_array(): void
    {
        self::assertSame(
            ['path' => "->items['a'][0]", 'class' => Container::class],
            ContainerReferenceFinder::find(new class (['a' => [0 => new Container()]]) {
                public function __construct(public readonly array $items) {}
            }),
        );
    }

    public function test_finds_a_container_in_a_closure_use_variable(): void
    {
        $container = new Container();

        self::assertSame(
            ['path' => '->callback{use $container}', 'class' => Container::class],
            ContainerReferenceFinder::find(new class (static fn () => $container) {
                public function __construct(public readonly \Closure $callback) {}
            }),
        );
    }

    public function test_finds_a_container_through_a_closure_bound_this(): void
    {
        $owner = new class (new Container()) {
            public function __construct(private readonly Container $container) {}

            public function run(): void {}
        };

        self::assertSame(
            ['path' => '->callback{this}->container', 'class' => Container::class],
            ContainerReferenceFinder::find(new class ($owner->run(...)) {
                public function __construct(public readonly \Closure $callback) {}
            }),
        );
    }

    public function test_a_static_closure_without_captured_variables_holds_no_container(): void
    {
        self::assertNull(ContainerReferenceFinder::find([static fn (): int => 1, \strlen(...)]));
    }

    public function test_finds_an_injector(): void
    {
        self::assertSame(
            ['path' => '->injector', 'class' => Injector::class],
            ContainerReferenceFinder::find(new class (new Injector(new Container())) {
                public function __construct(public readonly Injector $injector) {}
            }),
        );
    }

    public function test_finds_a_container_in_an_spl_object_storage(): void
    {
        $storage = new \SplObjectStorage();
        $storage[new \stdClass()] = 'no container here';
        $storage[new Container()] = null;

        self::assertSame(
            ['path' => '->storage[#1]', 'class' => Container::class],
            ContainerReferenceFinder::find(new class ($storage) {
                public function __construct(public readonly \SplObjectStorage $storage) {}
            }),
        );
    }

    public function test_finds_a_container_in_the_data_attached_to_an_spl_object_storage_entry(): void
    {
        $storage = new \SplObjectStorage();
        $storage[new \stdClass()] = ['k' => new Container()];

        self::assertSame(
            ['path' => "->storage[#0 data]['k']", 'class' => Container::class],
            ContainerReferenceFinder::find(new class ($storage) {
                public function __construct(public readonly \SplObjectStorage $storage) {}
            }),
        );
    }

    public function test_does_not_move_the_internal_pointer_of_an_spl_object_storage(): void
    {
        $first = new \stdClass();
        $second = new \stdClass();
        $storage = new \SplObjectStorage();
        $storage[$first] = 'a';
        $storage[$second] = 'b';
        $storage->rewind();
        $storage->next();

        self::assertNull(ContainerReferenceFinder::find(new class ($storage) {
            public function __construct(public readonly \SplObjectStorage $storage) {}
        }));

        self::assertTrue($storage->valid());
        self::assertSame(1, $storage->key());
        self::assertSame($second, $storage->current());
        self::assertSame('b', $storage->getInfo());
    }

    public function test_does_not_run_overridden_methods_of_an_spl_object_storage_subclass(): void
    {
        $storage = new RecordingStorage();
        $storage[new \stdClass()] = ['k' => new Container()];

        self::assertSame(
            ['path' => "->storage[#0 data]['k']", 'class' => Container::class],
            ContainerReferenceFinder::find(new class ($storage) {
                public function __construct(public readonly RecordingStorage $storage) {}
            }),
        );
        self::assertSame([], $storage->calls);
    }

    public function test_does_not_run_an_overridden_get_array_copy(): void
    {
        $bag = new RecordingArrayObject(['c' => new Container()]);

        self::assertSame(
            ['path' => "->bag['c']", 'class' => Container::class],
            ContainerReferenceFinder::find(new class ($bag) {
                public function __construct(public readonly RecordingArrayObject $bag) {}
            }),
        );
        self::assertSame([], $bag->calls);
    }

    public function test_finds_a_container_in_a_weak_map(): void
    {
        $key = new \stdClass();
        $map = new \WeakMap();
        $map[$key] = new Container();

        self::assertSame(
            ['path' => '->map[#0 data]', 'class' => Container::class],
            ContainerReferenceFinder::find(new class ($map) {
                public function __construct(public readonly \WeakMap $map) {}
            }),
        );
    }

    public function test_finds_a_container_in_an_array_object(): void
    {
        self::assertSame(
            ['path' => "->bag['c']", 'class' => Container::class],
            ContainerReferenceFinder::find(new class (new \ArrayObject(['c' => new Container()])) {
                public function __construct(public readonly \ArrayObject $bag) {}
            }),
        );
    }

    public function test_finds_a_container_in_an_array_iterator(): void
    {
        self::assertSame(
            ['path' => '->it[0]', 'class' => Container::class],
            ContainerReferenceFinder::find(new class (new \ArrayIterator([new Container()])) {
                public function __construct(public readonly \ArrayIterator $it) {}
            }),
        );
    }

    public function test_a_root_that_is_a_container_has_an_empty_path(): void
    {
        self::assertSame(
            ['path' => '', 'class' => Container::class],
            ContainerReferenceFinder::find(new Container()),
        );
    }

    public function test_skips_enums(): void
    {
        self::assertNull(ContainerReferenceFinder::find([Suit::Hearts, new class (Suit::Spades) {
            public function __construct(public readonly Suit $suit) {}
        }]));
    }

    public function test_terminates_on_an_array_that_holds_a_reference_to_itself(): void
    {
        $items = ['x' => 1];
        $items['self'] = &$items;

        self::assertNull(ContainerReferenceFinder::find(new class ($items) {
            public function __construct(public readonly array $items) {}
        }));
    }

    public function test_finds_a_container_in_an_array_that_holds_a_reference_to_itself(): void
    {
        $items = [];
        $items['self'] = &$items;
        $items['container'] = new Container();

        // The reference is followed once, and the container behind it is still reached.
        self::assertSame(
            ['path' => "->items['self']['container']", 'class' => Container::class],
            ContainerReferenceFinder::find(new class ($items) {
                public function __construct(public readonly array $items) {}
            }),
        );
    }

    public function test_terminates_on_a_root_array_that_holds_a_reference_to_itself(): void
    {
        $root = [];
        $root['self'] = &$root;

        self::assertNull(ContainerReferenceFinder::find($root));
    }

    public function test_terminates_on_cycles(): void
    {
        $a = new \stdClass();
        $b = new \stdClass();
        $a->b = $b;
        $b->a = $a;

        self::assertNull(ContainerReferenceFinder::find($a));
    }

    public function test_does_not_initialize_a_lazy_object(): void
    {
        $ghost = new \ReflectionClass(Holder::class)->newLazyGhost(static function (): void {
            throw new \LogicException('initialized');
        });

        self::assertNull(ContainerReferenceFinder::find(new class ($ghost) {
            public function __construct(public object $g) {}
        }));
        self::assertTrue(new \ReflectionClass(Holder::class)->isUninitializedLazyObject($ghost));
    }

    /**
     * @return iterable<string, array{\Closure(Container): object, string}>
     */
    public static function provideFinds_a_container_kept_by_an_uninitialized_lazy_object_without_initializing_itCases(): iterable
    {
        $class = new \ReflectionClass(LazyService::class);

        yield 'in the closure of a ghost' => [
            static fn (Container $container): object => $class->newLazyGhost(static function (LazyService $s) use ($container): void {
                $s->value = $container;
            }),
            '->g{initializer}{use $container}',
        ];
        yield 'in the factory of a proxy' => [
            static fn (Container $container): object => $class->newLazyProxy(static function () use ($container): LazyService {
                $s = new LazyService();
                $s->value = $container;

                return $s;
            }),
            '->g{initializer}{use $container}',
        ];
        yield 'in an initializer that is an object and a method' => [
            static fn (Container $container): object => $class->newLazyGhost([LazyService::factory($container), 'initialize']),
            '->g{initializer}[0]->held',
        ];
        yield 'in a property set before initialization' => [
            static function (Container $container) use ($class): object {
                $ghost = $class->newLazyGhost(static function (): void {});
                $class->getProperty('value')->setRawValueWithoutLazyInitialization($ghost, $container);

                return $ghost;
            },
            '->g->value',
        ];
    }

    /**
     * @param \Closure(Container): object $make
     */
    #[DataProvider('provideFinds_a_container_kept_by_an_uninitialized_lazy_object_without_initializing_itCases')]
    public function test_finds_a_container_kept_by_an_uninitialized_lazy_object_without_initializing_it(\Closure $make, string $path): void
    {
        $lazy = $make(new Container());

        self::assertSame(
            ['path' => $path, 'class' => Container::class],
            ContainerReferenceFinder::find(new class ($lazy) {
                public function __construct(public object $g) {}
            }),
        );
        self::assertTrue(new \ReflectionClass(LazyService::class)->isUninitializedLazyObject($lazy));
    }

    public function test_reports_an_uninitialized_lazy_container_without_initializing_it(): void
    {
        $ghost = new \ReflectionClass(Container::class)->newLazyGhost(static function (): void {
            throw new \LogicException('initialized');
        });

        self::assertSame(
            ['path' => '->g', 'class' => Container::class],
            ContainerReferenceFinder::find(new class ($ghost) {
                public function __construct(public object $g) {}
            }),
        );
        self::assertTrue(new \ReflectionClass(Container::class)->isUninitializedLazyObject($ghost));
    }
}

/**
 * A small named class: a lazy ghost can't be made from an anonymous one.
 */
class Holder
{
    public ?object $value = null;
}

class ContainerOwner
{
    public function __construct(private readonly Container $container) {}
}

/**
 * Records every call to a method the walk could trigger by iterating the storage.
 */
class RecordingStorage extends \SplObjectStorage
{
    /** @var list<string> */
    public array $calls = [];

    public function rewind(): void
    {
        $this->calls[] = 'rewind';
        parent::rewind();
    }

    public function valid(): bool
    {
        $this->calls[] = 'valid';

        return parent::valid();
    }

    public function key(): int
    {
        $this->calls[] = 'key';

        return parent::key();
    }

    public function current(): object
    {
        $this->calls[] = 'current';

        return parent::current();
    }

    public function next(): void
    {
        $this->calls[] = 'next';
        parent::next();
    }

    public function getInfo(): mixed
    {
        $this->calls[] = 'getInfo';

        return parent::getInfo();
    }

    public function offsetGet(mixed $object): mixed
    {
        $this->calls[] = 'offsetGet';

        return parent::offsetGet($object);
    }

    public function __serialize(): array
    {
        $this->calls[] = '__serialize';

        return parent::__serialize();
    }

    public function __debugInfo(): array
    {
        $this->calls[] = '__debugInfo';

        return parent::__debugInfo();
    }
}

class RecordingArrayObject extends \ArrayObject
{
    /** @var list<string> */
    public array $calls = [];

    public function getArrayCopy(): array
    {
        $this->calls[] = 'getArrayCopy';

        return parent::getArrayCopy();
    }
}

enum Suit
{
    case Hearts;
    case Spades;
}
