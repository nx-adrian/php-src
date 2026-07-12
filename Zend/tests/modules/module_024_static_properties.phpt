--TEST--
Modules: module-level static properties are deferred (compile error)
--FILE--
<?php
// Module-level `static` property members are deferred to a future proposal (module state /
// instantiable modules). Declaring one is a compile-time error; a module may still declare
// classes, interfaces, enums, traits, constants, and nested modules.
module Counter {
    public static int $count = 0;
}
?>
--EXPECTF--
Fatal error: Module-level static properties are not supported (deferred to a future proposal); a module may declare classes, interfaces, enums, traits, constants, and nested modules in %s on line %d
