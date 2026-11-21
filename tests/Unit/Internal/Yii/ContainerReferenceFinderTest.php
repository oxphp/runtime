<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Unit\Internal\Yii;

use OxPHP\Runtime\Internal\Yii\ContainerReferenceFinder;
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

enum Suit
{
    case Hearts;
    case Spades;
}
