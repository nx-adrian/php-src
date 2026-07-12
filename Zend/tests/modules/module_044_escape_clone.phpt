--TEST--
Modules: internal __clone() gates cloning of an escaped internal object (like private __clone)
--FILE--
<?php
module M {
    internal class Widget {
        public int $n = 1;
        internal function __clone() {}          // block cloning from outside its module
    }
    public class Freely {
        public int $n = 2;                      // no __clone: cloneable from anywhere
    }
    public class Api {
        public static function make(): Widget { return new module::Widget; }
        public static function makeFreely(): Freely { return new module::Freely; }
        public static function cloneInside(Widget $w): Widget { return clone $w; }  // same module: allowed
    }
}

$w = M::Api::make();
$f = M::Api::makeFreely();

// Outside the module: internal __clone blocks both the clone opcode and clone().
try { $c = clone $w; echo "opcode: cloned\n"; }
catch (\Error $e) { echo "opcode: ", $e->getMessage(), "\n"; }

try { $c = clone($w); echo "fn: cloned\n"; }
catch (\Error $e) { echo "fn: ", $e->getMessage(), "\n"; }

// Inside the module: cloning is allowed.
$ci = M::Api::cloneInside($w);
echo "inside: cloned n=", $ci->n, "\n";

// A public class with no __clone stays cloneable from outside (unchanged behavior).
$fc = clone $f;
echo "freely: cloned n=", $fc->n, "\n";
?>
--EXPECT--
opcode: Cannot call internal method M::Widget::__clone() from outside its module
fn: Cannot call internal method M::Widget::__clone() from outside its module
inside: cloned n=1
freely: cloned n=2
