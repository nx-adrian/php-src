<?php
// A quick tour of the php-modules branch. Run with the built CLI:
//   ./sapi/cli/php tmp_build/sample.php

module Billing {
    public const CURRENCY = "USD";
    internal const RETRY_LIMIT = 3;                 // internal: module-only

    public static int $invoiceCount = 0;
    public static function nextId(): int { return ++module::$invoiceCount; }

    public interface Payable {}

    public class Invoice implements Payable {
        public const KIND = "invoice";
        public static function make(): string { return "invoice#" . module::nextId(); }
        public function total(): int { return 100; }
    }

    internal class Ledger {                          // internal: module-only
        public static function record(): string { return "recorded"; }
    }

    public class Gateway {
        // internal collaboration inside the module boundary
        public function post(): string { return module::Ledger::record(); }
        public function limit(): int { return module::RETRY_LIMIT; }
    }
}

echo "== public surface ==\n";
echo "constant           : ", Billing::CURRENCY, "\n";
echo "static method      : ", Billing::Invoice::make(), "\n";     // chained Module::Class::method
echo "static method      : ", Billing::Invoice::make(), "\n";
echo "static prop        : ", Billing::$invoiceCount, "\n";
echo "member const       : ", Billing::Invoice::KIND, "\n";       // chained Module::Class::CONST
echo "instanceof         : ", var_export(new Billing::Invoice instanceof Billing::Payable, true), "\n";
echo "internal via module: ", (new Billing::Gateway())->post(), "\n";   // module::Ledger reachable inside
echo "internal const     : ", (new Billing::Gateway())->limit(), "\n";

echo "\n== reflection ==\n";
$r = new ReflectionModule("Billing");
echo "module name        : ", $r->getName(), "\n";
echo "classes            : ", implode(", ", $r->getClasses()), "\n";
echo "Invoice visibility : ", $r->getSymbolVisibility("Billing::Invoice"), "\n";
echo "Ledger visibility  : ", $r->getSymbolVisibility("Billing::Ledger"), "\n";

// NOTE: a *static* reference to an internal member from outside (e.g.
// `new Billing::Ledger()`) is a COMPILE-time error and is not catchable — that is
// the compile-time half of the boundary. Here we use dynamic (string) references,
// which are caught at runtime, so the demo can show the boundary without aborting.
echo "\n== the boundary (denied from out here; runtime enforcement) ==\n";
$probes = [
    "instantiate module" => function () { $c = "Billing";          return new $c(); },
    "internal class"      => function () { $c = "Billing::Ledger";  return new $c(); },
    "internal constant"   => function () { return constant("Billing::RETRY_LIMIT"); },
    "internal method"     => function () { $c = "Billing::Ledger";  return $c::record(); },
];
foreach ($probes as $label => $probe) {
    try { $probe(); echo sprintf("%-19s: LEAKED\n", $label); }
    catch (\Throwable $e) { echo sprintf("%-19s: blocked (%s)\n", $label, $e->getMessage()); }
}
