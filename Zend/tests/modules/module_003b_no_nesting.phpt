--TEST--
Modules: module declarations cannot be nested (compile-time fatal)
--FILE--
<?php
module A { module B { class C {} } }
?>
--EXPECTF--
Fatal error: Module declarations cannot be nested in %s on line %d
