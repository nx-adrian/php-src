--TEST--
Modules: "module::" outside any module is a compile-time error
--FILE--
<?php
class Foo {}
$x = new module::Foo();
?>
--EXPECTF--
Fatal error: "module::" cannot be used outside a module in %s on line %d
