--TEST--
Modules: dynamic "instanceof $name" sees through an internal type (identity), like literal instanceof / is_a
--FILE--
<?php
module M {
    internal class Sec {}
    public static function make(): Sec { return new module::Sec; }
}
$o = M::make();
$n = "M::Sec";

// Identity/observation is not gated, regardless of literal vs dynamic form.
var_dump($o instanceof M::Sec);   // literal
var_dump($o instanceof $n);       // dynamic (was wrongly denied before)
var_dump(is_a($o, "M::Sec"));
var_dump(is_a($o, $n));
var_dump((new stdClass) instanceof $n);   // dynamic, non-matching -> false (not an error)

// But *use* stays gated even via a dynamic name.
try { $x = new $n; echo "LEAK\n"; } catch (\Error $e) { echo "new denied\n"; }
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
new denied
