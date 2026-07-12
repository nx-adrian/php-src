--TEST--
Modules: every module has a backing class (identity marker), even with no static members
--FILE--
<?php
module Widgets {
    public class Button {}
    internal class Rivet {}
}

// A member-only module still materializes its backing class — the runtime identity
// marker used by autoload, reflection, and the shared-symbol rule.
var_dump(class_exists("Widgets"));

// Not instantiable
try { new Widgets(); } catch (\Error $e) { echo $e->getMessage(), "\n"; }

// Members still resolve as before
var_dump(new Widgets::Button instanceof Widgets::Button);
?>
--EXPECT--
bool(true)
Cannot instantiate module Widgets
bool(true)
