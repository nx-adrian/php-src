--TEST--
Modules: "module::Member" self-reference in new / type / extends / instanceof
--FILE--
<?php
module Billing {
    public class Ledger {
        public function tag(): string { return "L"; }
    }

    public class Invoice {
        // return type via module::, body constructs via module::
        public function make(): module::Ledger {
            return new module::Ledger();
        }
        // instanceof via module:: (internal collaboration inside the module)
        public function isLedger(object $o): bool {
            return $o instanceof module::Ledger;
        }
    }

    // extends via module::
    public class BigLedger extends module::Ledger {}

    // module:: also reaches an *internal* member from inside the module
    internal class Secret {
        public function ping(): string { return "secret"; }
    }
    public class Gateway {
        public function reach(): string {
            return (new module::Secret())->ping();
        }
    }
}

$inv = new Billing::Invoice();
$l = $inv->make();
echo get_class($l), " ", $l->tag(), "\n";
var_dump($inv->isLedger($l));
var_dump($inv->isLedger(new stdClass()));
var_dump(new Billing::BigLedger() instanceof Billing::Ledger);
echo (new Billing::Gateway())->reach(), "\n";
?>
--EXPECT--
Billing::Ledger L
bool(true)
bool(false)
bool(true)
secret
