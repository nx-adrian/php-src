--TEST--
Modules: public members are visible everywhere; internal members only inside the module
--FILE--
<?php
module Billing {
    public class Invoice {
        public function makeLedger(): object {
            // Internal collaboration within the module boundary is allowed.
            return new Billing::Ledger();
        }
    }
    internal class Ledger {
        public function tag(): string { return "ledger"; }
    }
}

// public class from outside: allowed
$inv = new Billing::Invoice();
echo $inv::class, "\n";

// internal class used from inside the module (via a public method): allowed
echo $inv->makeLedger()->tag(), "\n";
echo $inv->makeLedger()::class, "\n";
?>
--EXPECT--
Billing::Invoice
ledger
Billing::Ledger
