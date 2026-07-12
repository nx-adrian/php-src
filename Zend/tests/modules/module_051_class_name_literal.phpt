--TEST--
Modules: "Module::Member::class" yields the member's canonical name (like get_class / $obj::class)
--FILE--
<?php
module M {
    public class C {}
    internal class Sec {}
    public module Inner { public class D {} }
}

// ::class on a module member class named by "::" yields the FQN, at any depth.
var_dump(M::C::class);
var_dump(\M::C::class);
var_dump(M::Inner::D::class);

// ::class is identity/observation, so it works even for an internal type (it only
// produces the name string; it does not construct, extend, or reach members).
var_dump(M::Sec::class);

// Agrees with the object-based forms.
$o = new M::C;
var_dump($o::class === M::C::class);
var_dump(get_class($o) === M::C::class);
?>
--EXPECT--
string(4) "M::C"
string(4) "M::C"
string(11) "M::Inner::D"
string(6) "M::Sec"
bool(true)
bool(true)
