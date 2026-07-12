--TEST--
Modules: internal methods stay virtual WITHIN the module and the seal does not over-restrict
--FILE--
<?php
module M {
    public class Base {
        internal function f(): string { return "base-f"; }
        public function callF(): string { return $this->f(); }
    }
    public class Derived extends Base {
        internal function f(): string { return "derived-f"; }   // in-module override: allowed
    }
    public class Pub {
        public function g(): string { return "pub-g"; }
    }
}

// In-module override is virtual: Derived::callF() dispatches to Derived::f.
echo (new M::Derived)->callF(), "\n";

// An outside subclass that does NOT override inherits the internal method; the module's
// own call still reaches Base::f (no hijack, nothing broken).
class Outside extends M::Base {}
echo (new Outside)->callF(), "\n";

// Overriding a PUBLIC module method from outside is unaffected by the seal.
class PubSub extends M::Pub { public function g(): string { return "overridden"; } }
echo (new PubSub)->g(), "\n";

// Reaching the internal method from outside (via parent::) is still denied — the access
// side stays private-style even though the method is virtual inside the module.
class Caller extends M::Base {
    public function reach(): string { try { return parent::f(); } catch (\Error $e) { return "denied"; } }
}
echo (new Caller)->reach(), "\n";
?>
--EXPECT--
derived-f
base-f
overridden
denied
