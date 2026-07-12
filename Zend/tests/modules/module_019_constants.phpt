--TEST--
Modules: module-level constants live on a non-instantiable backing class (M::C / module::C)
--FILE--
<?php
module Billing {
    public const RATE = 15;
    public const LABEL = "vat";
    public const DERIVED = Billing::RATE * 2;   // const-expr referencing a sibling const

    public class Calc {
        // module::CONST self-reference from inside the module
        public function tax(int $n): int { return $n * module::RATE / 100; }
        public function label(): string { return module::LABEL; }
    }

    // A member class may share a name with a constant — no conflict (position-directed)
    public const Invoice = 99;
    public class Invoice {}
}

// External access via M::C
echo Billing::RATE, " ", Billing::LABEL, " ", Billing::DERIVED, "\n";
// Internal self-reference via module::C
$c = new Billing::Calc();
echo $c->tax(200), " ", $c->label(), "\n";
// Constant and member class of the same name coexist:
echo Billing::Invoice, " ";                 // the constant
var_dump(new Billing::Invoice instanceof Billing::Invoice);   // the class
// module::class resolves to the backing class name
echo Billing::class, "\n";

// The backing class is not instantiable
try {
    new Billing();
    echo "INSTANTIATED\n";
} catch (\Error $e) {
    echo "not instantiable\n";
}
?>
--EXPECT--
15 vat 30
30 vat
99 bool(true)
Billing
not instantiable
