--TEST--
Modules: "use Module::Member" imports a module member (default/explicit alias, chained, internal)
--FILE--
<?php
module Billing {
    public class Ledger { public function tag(): string { return "ledger"; } }
    public class Invoice { public function tag(): string { return "invoice"; } }
    internal class Secret { public function s(): string { return "secret"; } }
}
module VendorName\User {
    public class Profile { public function tag(): string { return "profile"; } }
}
module Outer {
    public module Inner {
        public class Gadget { public function who(): string { return "gadget"; } }
    }
}

use Billing::Ledger;              // default alias "Ledger"
use Billing::Invoice as Inv;      // explicit alias
use VendorName\User::Profile;     // namespaced module name
use Outer::Inner::Gadget;         // chained, default alias "Gadget"

echo (new Ledger)->tag(), "\n";
echo (new Inv)->tag(), "\n";
echo (new Profile)->tag(), "\n";
echo (new Gadget)->who(), "\n";

// The alias participates in type hints and instanceof like any imported class name.
function useLedger(Ledger $l): string { return $l instanceof Ledger ? "yes" : "no"; }
echo useLedger(new Ledger), "\n";

// Importing an internal member is allowed (use is pure aliasing); the ACCESS is what
// is gated — so instantiating it from outside the module still fails at runtime.
use Billing::Secret;
try { new Secret(); echo "LEAKED\n"; }
catch (\Error $e) { echo $e->getMessage(), "\n"; }
?>
--EXPECT--
ledger
invoice
profile
gadget
yes
Cannot access internal module member "Billing::Secret" from outside its module
