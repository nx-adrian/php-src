--TEST--
Modules: a module name cannot collide with an existing class (shared symbol space)
--FILE--
<?php
class Dup {}
module Dup { class Q {} }
?>
--EXPECTF--
Fatal error: Cannot declare module "Dup": a class with that name already exists in %s on line %d
