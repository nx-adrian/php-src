--TEST--
Modules: nested modules (flat boundary model) — "Outer::Inner" naming, members, reflection
--FILE--
<?php
module Outer {
    public const OV = "outer";
    public class Widget {}

    public module Inner {
        public const IV = "inner";
        public static function make(): string { return "inner-make"; }
        public class Gadget {
            public function who(): string { return "gadget"; }
            public static function tag(): string { return "g"; }
        }
    }
}

// Outer members unchanged
echo Outer::OV, "\n";
var_dump(new Outer::Widget instanceof Outer::Widget);

// The nested module is canonically "Outer::Inner" (module boundary is "::").
var_dump(class_exists("Outer::Inner"));

// Nested module-level members via chained "::"
echo Outer::Inner::IV, "\n";
echo Outer::Inner::make(), "\n";

// Nested member class: static access via chain, and direct instantiation through
// the multi-"::" class reference "new Outer::Inner::Gadget".
echo Outer::Inner::Gadget::tag(), "\n";
echo (new Outer::Inner::Gadget)->who(), "\n";

// Reflection sees the nested module and its members
$r = new ReflectionModule("Outer::Inner");
echo $r->getName(), " :: ", implode(",", $r->getClasses()), "\n";
?>
--EXPECT--
outer
bool(true)
bool(true)
inner
inner-make
g
gadget
Outer::Inner :: Outer::Inner::Gadget
