--TEST--
Modules: referencing an internal member from outside is a RUNTIME error (like private/protected)
--FILE--
<?php
module Billing {
    public class Invoice {}
    internal class Ledger {}
}

// Internal-member access is a runtime property, exactly like private/protected:
// a reference in never-executed code does not error...
echo "before\n";
if (false) {
    new Billing::Ledger();                 // never reached -> no error
}
function never_called() {
    return new Billing::Ledger();          // never called -> no error
}
echo "after\n";

// ...and enforcement fires only when the access actually runs.
try {
    new Billing::Ledger();
    echo "LEAKED\n";
} catch (\Error $e) {
    echo $e->getMessage(), "\n";
}

// The public member is reachable, as always.
var_dump(new Billing::Invoice instanceof Billing::Invoice);
?>
--EXPECT--
before
after
Cannot access internal module member "Billing::Ledger" from outside its module
bool(true)
