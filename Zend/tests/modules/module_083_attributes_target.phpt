--TEST--
Modules: TARGET_MODULE gating -- a class-only attribute cannot target a module, and vice versa
--DESCRIPTION--
The module target participates in normal attribute target validation. A user attribute
declared for TARGET_CLASS only throws when instantiated against a module; an attribute
declared for TARGET_MODULE throws when instantiated against a class; an attribute with
the default TARGET_ALL applies to both. This mirrors class/property/etc. target checks.
--FILE--
<?php
#[Attribute(Attribute::TARGET_CLASS)]
class ClassOnly {}

#[Attribute(Attribute::TARGET_MODULE)]
class ModuleOnly {}

#[Attribute] // defaults to TARGET_ALL
class Anywhere {}

#[ClassOnly]
#[ModuleOnly]
#[Anywhere]
module M { public class C {} }

#[ModuleOnly]
#[Anywhere]
class PlainClass {}

$m = new ReflectionModule("M");

// TARGET_MODULE and TARGET_ALL are fine on a module.
echo "ModuleOnly on module: ", get_class($m->getAttributes("ModuleOnly")[0]->newInstance()), "\n";
echo "Anywhere on module: ", get_class($m->getAttributes("Anywhere")[0]->newInstance()), "\n";

// A class-only attribute cannot target a module.
try {
    $m->getAttributes("ClassOnly")[0]->newInstance();
} catch (\Error $e) {
    echo "reject: ", $e->getMessage(), "\n";
}

// Symmetric direction: a module-only attribute cannot target a class.
$c = new ReflectionClass("PlainClass");
try {
    $c->getAttributes("ModuleOnly")[0]->newInstance();
} catch (\Error $e) {
    echo "reject: ", $e->getMessage(), "\n";
}
?>
--EXPECT--
ModuleOnly on module: ModuleOnly
Anywhere on module: Anywhere
reject: Attribute "ClassOnly" cannot target module (allowed targets: class)
reject: Attribute "ModuleOnly" cannot target class (allowed targets: module)
