--TEST--
Modules: a module must be declared in the root namespace (compile-time fatal)
--FILE--
<?php
namespace X;
module Y { public class Z {} }
?>
--EXPECTF--
Fatal error: Module declaration "Y" must be in the root namespace in %s on line %d
