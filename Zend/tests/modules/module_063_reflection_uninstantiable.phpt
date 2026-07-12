--TEST--
Modules: ReflectionClass reports a module as non-instantiable and non-cloneable
--DESCRIPTION--
A module is backed by a synthetic non-instantiable class (`new M` fatals with "Cannot
instantiate module"). Reflection must agree: isInstantiable() and isCloneable() return false
for a module, exactly as they do for interfaces/traits/enums/abstract classes. A module's
member classes are unaffected.
--FILE--
<?php
module M {
    public const K = 1;
    public class A {}
    internal class B {}
}

$m = new ReflectionClass('M');
var_dump($m->isInstantiable());
var_dump($m->isCloneable());

// Member classes remain ordinary instantiable/cloneable classes.
$a = new ReflectionClass('M::A');
var_dump($a->isInstantiable(), $a->isCloneable());
$b = new ReflectionClass('M::B');
var_dump($b->isInstantiable(), $b->isCloneable());

// The engine guard is unchanged.
try { new M(); } catch (\Error $e) { echo $e->getMessage(), "\n"; }
?>
--EXPECT--
bool(false)
bool(false)
bool(true)
bool(true)
bool(true)
bool(true)
Cannot instantiate module M
