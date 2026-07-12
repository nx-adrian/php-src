--TEST--
Modules: module-level static functions are deferred (compile error)
--FILE--
<?php
// Module-level `static function` members are deferred to a future proposal (module state /
// instantiable modules). Declaring one is a compile-time error; a module may still declare
// classes, interfaces, enums, traits, constants, and nested modules — and class-level static
// methods on member classes are unaffected.
module Billing {
    public static function tax(int $n): int { return $n; }
}
?>
--EXPECTF--
Fatal error: Module-level static functions are not supported (deferred to a future proposal); a module may declare classes, interfaces, enums, traits, constants, and nested modules in %s on line %d
