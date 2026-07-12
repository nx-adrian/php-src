--TEST--
Modules: an internal method is sealed to outside subclasses (cannot be overridden)
--FILE--
<?php
module M {
    public class Base {
        internal function f(): string { return "internal-f"; }
        public function callF(): string { return $this->f(); }
    }
}
// An outside subclass may not override the internal method: that would let external code
// replace, and so hijack, the module's own $this->f() dispatch. Sealed like `final`, but
// scoped to the module boundary (an in-module subclass may still override it).
class Sub extends M::Base {
    public function f(): string { return "HIJACK"; }
}
?>
--EXPECTF--
Fatal error: Cannot override internal method M::Base::f() from outside its module in %s on line %d
