--TEST--
Modules: module static functions on the backing class (M::f() / module::f(), internal)
--FILE--
<?php
module Billing {
    public const RATE = 15;

    public static function tax(int $n): int {
        return $n * module::RATE / 100;        // module::CONST inside a static fn
    }
    public static function label(int $n): string {
        return "vat:" . module::tax($n);        // module::f() self-call
    }

    internal static function secret(): string { return "hidden"; }

    public class Helper {
        public function reach(): string {
            return module::secret();            // internal static reachable inside the module
        }
    }
}

echo Billing::tax(200), "\n";                    // external static call -> 30
echo Billing::label(100), "\n";                  // self-call chain -> vat:15
echo (new Billing::Helper())->reach(), "\n";     // internal from inside -> hidden

echo "outside internal: ";
try {
    Billing::secret();                           // internal static from outside -> blocked
} catch (\Error $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
30
vat:15
hidden
outside internal: Cannot call internal method Billing::secret() from outside its module
