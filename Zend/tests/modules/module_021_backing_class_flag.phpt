--TEST--
Modules: the backing class is a module (not "abstract") — instantiation message
--FILE--
<?php
module Payments {
    public const MODE = "live";
    public class Gateway {}
}

// Non-instantiable, with a module-specific message (not "abstract class")
try {
    new Payments();
} catch (\Error $e) {
    echo $e->getMessage(), "\n";
}

// The module's own members are unaffected
echo Payments::MODE, " ", (new Payments::Gateway() instanceof Payments::Gateway ? "ok" : "no"), "\n";
?>
--EXPECT--
Cannot instantiate module Payments
live ok
