--TEST--
Modules: internal static properties/constants are rejected until enforcement lands
--FILE--
<?php
module M {
    internal static int $s = 1;
}
?>
--EXPECTF--
Fatal error: internal module properties are not yet supported; declare the property public in %s on line %d
