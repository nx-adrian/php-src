--TEST--
Modules: module types compose with the language (union/nullable types, match, anon class, LSB, __invoke, clone)
--FILE--
<?php
module App {
    public interface Shape { public function area(): float; }
    public enum Kind { case A; case B; }
    public class Circle implements Shape {
        public function __construct(public float $r = 1.0) {}
        public function area(): float { return $this->r ** 2; }
        public function __invoke(): string { return "invoked"; }
        public static function of(float $r): static { return new static($r); }
    }
}

// nullable/union parameter typed with a module type
$f = function (App::Shape|null $s): string { return $s === null ? "null" : "shape"; };
echo $f(new App::Circle), " ", $f(null), "\n";

// match on a module enum
echo match (App::Kind::A) { App::Kind::A => "a", App::Kind::B => "b" }, "\n";

// anonymous class implementing a module interface
$anon = new class implements App::Shape { public function area(): float { return 2.5; } };
var_dump($anon instanceof App::Shape, $anon->area());

// arrow fn returning a module object; late static binding
$make = fn(float $r): App::Circle => App::Circle::of($r);
$c = $make(3.0);
var_dump($c instanceof App::Circle, $c->r);

// __invoke and clone of a public module object
echo $c(), "\n";
$clone = clone $c;
var_dump($clone !== $c, $clone->r);

// first-class callable of a module static method
$mk = App::Circle::of(...);
var_dump($mk(4.0)->r);
?>
--EXPECT--
shape null
a
bool(true)
float(2.5)
bool(true)
float(3)
invoked
bool(true)
float(3)
float(4)
