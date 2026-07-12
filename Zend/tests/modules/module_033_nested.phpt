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

// Nested member class: static access via chain; instance via dynamic name (the
// 3-segment class-reference form `new Outer::Inner::Gadget` is a known grammar gap).
echo Outer::Inner::Gadget::tag(), "\n";
$c = "Outer::Inner::Gadget";
echo (new $c)->who(), "\n";

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
