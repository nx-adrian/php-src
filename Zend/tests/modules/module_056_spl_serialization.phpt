--TEST--
Modules: module classes compose with SPL/magic interfaces, serialization, generators, and arg forms
--FILE--
<?php
module Coll {
    public class Bag implements \Countable, \IteratorAggregate, \JsonSerializable, \ArrayAccess {
        private array $items;
        public function __construct(int ...$items) { $this->items = $items; }
        public function count(): int { return count($this->items); }
        public function getIterator(): \Iterator { return new \ArrayIterator($this->items); }
        public function jsonSerialize(): mixed { return $this->items; }
        public function offsetExists($o): bool { return isset($this->items[$o]); }
        public function offsetGet($o): mixed { return $this->items[$o] ?? null; }
        public function offsetSet($o, $v): void { if ($o === null) $this->items[] = $v; else $this->items[$o] = $v; }
        public function offsetUnset($o): void { unset($this->items[$o]); }
    }
    public class Point {
        public function __construct(public readonly int $x = 0, public readonly int $y = 0) {}
        public function label(): string { return "($this->x,$this->y)"; }
    }
}

$bag = new Coll::Bag(1, 2, 3);      // variadic module constructor
echo count($bag), "\n";              // Countable
$sum = 0; foreach ($bag as $v) $sum += $v; echo $sum, "\n";   // IteratorAggregate
$bag[] = 4; echo $bag[3], "\n";      // ArrayAccess
echo json_encode($bag), "\n";        // JsonSerializable

// serialization round-trip of a public module object
$p = new Coll::Point(3, 4);
$p2 = unserialize(serialize($p));
var_dump($p2 instanceof Coll::Point, $p2->label());

// named arguments to a module constructor
echo (new Coll::Point(y: 9, x: 8))->label(), "\n";

// generator yielding module objects
$gen = (function() { yield new Coll::Point(1,1); yield new Coll::Point(2,2); })();
echo iterator_to_array($gen)[1]->label(), "\n";

// WeakMap keyed by a module object
$wm = new WeakMap(); $wm[$p] = "meta";
echo $wm[$p], " ", count($wm), "\n";
?>
--EXPECT--
3
6
4
[1,2,3,4]
bool(true)
string(5) "(3,4)"
(8,9)
(2,2)
meta 1
