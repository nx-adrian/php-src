--TEST--
Modules: an internal class cannot be re-instantiated from outside via an escaped instance
--FILE--
<?php
module M {
    internal class Widget {
        public function pub(): string { return "pub"; }
    }
    public class Api {
        public static function make(): Widget { return new module::Widget; }
        // Same-module re-instantiation from an escaped object must still work.
        public static function reNew(Widget $w): Widget { return new $w; }
    }
}

$w = M::Api::make();

// Public surface of the escaped object still usable.
echo "surface: ", $w->pub(), "\n";

// Object-based "new $obj" from outside is denied (was the leak).
try { $x = new $w; echo "new \$w: made\n"; }
catch (\Error $e) { echo "new \$w: ", $e->getMessage(), "\n"; }

// Name-derived paths were already denied; confirm still denied.
try { $x = new ($w::class); echo "new ::class: made\n"; }
catch (\Error $e) { echo "new ::class: ", $e->getMessage(), "\n"; }

$cn = get_class($w);
try { $x = new $cn; echo "new get_class: made\n"; }
catch (\Error $e) { echo "new get_class: ", $e->getMessage(), "\n"; }

// Inside the module, re-instantiation from the escaped object is allowed.
echo "inside: ", get_class(M::Api::reNew($w)), "\n";
?>
--EXPECT--
surface: pub
new $w: Cannot instantiate internal module member "M::Widget" from outside its module
new ::class: Cannot access internal module member "M::Widget" from outside its module
new get_class: Cannot access internal module member "M::Widget" from outside its module
inside: M::Widget
