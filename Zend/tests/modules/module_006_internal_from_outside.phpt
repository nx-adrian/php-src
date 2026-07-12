--TEST--
Modules: statically referencing an internal member from outside the module is a compile error
--FILE--
<?php
module Billing {
    public class Invoice {}
    internal class Ledger {}
}

new Billing::Ledger();
?>
--EXPECTF--
Fatal error: Cannot access internal module member "Billing::Ledger" from outside its module in %s on line %d
