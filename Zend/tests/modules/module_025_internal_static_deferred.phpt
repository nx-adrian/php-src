--TEST--
Modules: internal constants are rejected until the fetch gate lands (properties now work)
--FILE--
<?php
module M {
    internal const K = 1;
}
?>
--EXPECTF--
Fatal error: internal module constants are not yet supported; declare the constant public in %s on line %d
