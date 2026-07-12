--TEST--
Modules: visibility is optional inside a module block and defaults to public
--DESCRIPTION--
Inside a `module { }` block a member with no visibility keyword defaults to public — for
every member kind: class, interface, trait, enum, module-level const, a nested module, and a body-less claim. `internal` must still be written
explicitly, and an explicit internal member stays gated from outside. (Unclaimed split-file
symbols default to internal instead — that boundary is exercised in module_048.)
--FILE--
<?php
module M {
    class A {}                                     // -> public
    interface I {}                                 // -> public
    trait T { public function hi(): string { return "hi"; } }  // -> public
    enum E: int { case One = 1; }                  // -> public
    const K = 41;                                  // -> public
    module Inner { class G {} }                    // -> public nested module + public member
    internal class X {}                            // explicit internal (gated outside)
    public class B {}                              // explicit public (unchanged)
}

// class / interface / trait / enum are public and usable from outside
$a = new M::A();
var_dump($a instanceof M::A);
class Impl implements M::I {}
var_dump(new Impl() instanceof M::I);
class UsesT { use M::T; }
echo (new UsesT())->hi(), "\n";
echo M::E::One->value, "\n";

// module-level const is public
echo M::K, "\n";

// nested module + its member are public
var_dump(new M::Inner::G() instanceof M::Inner::G);

// reflection: an unmarked member reports public; an explicit internal one reports internal
$r = new ReflectionModule("M");
echo $r->getSymbolVisibility("M::A"), " ", $r->getSymbolVisibility("M::X"), "\n";
var_dump((new ReflectionClass("M::A"))->isModuleInternal());

// explicit internal is still enforced from outside
try { new M::X(); echo "X LEAK\n"; }
catch (\Error $e) { echo $e->getMessage(), "\n"; }
?>
--EXPECT--
bool(true)
bool(true)
hi
1
41
bool(true)
public internal
bool(false)
Cannot access internal module member "M::X" from outside its module
