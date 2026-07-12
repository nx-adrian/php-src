--TEST--
Modules: a public class with an internal constructor is instantiable only inside the module
--FILE--
<?php
module Billing {
    // publicly visible, but constructible only from within the module
    public class TaxReport {
        internal function __construct(public array $data) {}
        public function total(): int { return array_sum($this->data); }
    }
    public static function report(array $d): TaxReport {
        return new module::TaxReport($d);                     // inside: allowed
    }
    // fully public class for contrast
    public class Config {
        public function __construct(public string $env = "prod") {}
    }
}

// From inside the module (via the factory)
echo Billing::report([10, 20, 30])->total(), "\n";           // 60

// A fully public class is freely constructible
echo (new Billing::Config("dev"))->env, "\n";                 // dev

// The internal constructor denies direct construction from outside
echo "outside: ";
try {
    new Billing::TaxReport([1, 2]);
    echo "LEAKED\n";
} catch (\Error $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
60
dev
outside: Cannot instantiate class Billing::TaxReport via internal constructor from outside its module
