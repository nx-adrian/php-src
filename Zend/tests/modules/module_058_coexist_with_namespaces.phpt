--TEST--
Modules: a module block coexists with bracketed namespaces in the same file (any order); the module stays in root
--FILE--
<?php
namespace A {
    class D {}
}

module X\Y {
    public class C {}
    public const V = 1;
}

namespace B {
    // The module is in the ROOT namespace (X\Y::C), not A\ or B\.
    var_dump(class_exists('X\\Y::C'));
    var_dump(class_exists('A\\X\\Y::C'));
    echo (new \X\Y::C) instanceof \X\Y::C ? "module ok\n" : "no\n";
    echo \X\Y::V, "\n";
    echo (new \A\D) instanceof \A\D ? "ns A ok\n" : "no\n";
}
?>
--EXPECT--
bool(true)
bool(false)
module ok
1
ns A ok
