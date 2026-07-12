--TEST--
Modules: a module's backing class is not a base class (compile-time error)
--FILE--
<?php
module Payments {
    public const MODE = "live";
}
class MyGateway extends Payments {}
?>
--EXPECTF--
Fatal error: Class MyGateway cannot extend module Payments in %s on line %d
