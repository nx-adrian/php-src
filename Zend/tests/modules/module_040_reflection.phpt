--TEST--
Modules: ReflectionModule surface (kinds, functions, constants, visibility) + isModuleInternal
--FILE--
<?php
module Shop {
    public class Cart {}
    internal class Ledger {}
    public interface Payable {}
    public enum Status { case A; }
    public const VERSION = "1.2.3";
    internal const SECRET = 42;
    public static function calc(): int { return 1; }
    internal static function log(): void {}
    public module Sub { public class Widget {} }

    public class Widget {
        internal function secret(): void {}
        public function open(): void {}
        internal string $token = "t";
        public string $name = "n";
        internal const HIDDEN = 1;
        public const SHOWN = 2;
    }
}

$r = new ReflectionModule("Shop");
echo "name:       ", $r->getName(), "\n";
echo "classes:    ", implode(",", $r->getClasses()), "\n";
echo "interfaces: ", implode(",", $r->getInterfaces()), "\n";
echo "enums:      ", implode(",", $r->getEnums()), "\n";
echo "modules:    ", implode(",", $r->getModules()), "\n";
echo "functions:  ", implode(",", $r->getFunctions()), "\n";
echo "constants:  ", json_encode($r->getConstants()), "\n";
echo "vis Cart:   ", $r->getSymbolVisibility("Shop::Cart"), "\n";
echo "vis Ledger: ", $r->getSymbolVisibility("Shop::Ledger"), "\n";

// isModuleInternal across reflection targets (distinct from isInternal()).
$w = new ReflectionClass("Shop::Widget");
printf("class L/C:  %d/%d\n", (new ReflectionClass("Shop::Ledger"))->isModuleInternal(), (new ReflectionClass("Shop::Cart"))->isModuleInternal());
printf("method s/o: %d/%d\n", $w->getMethod("secret")->isModuleInternal(), $w->getMethod("open")->isModuleInternal());
printf("prop t/n:   %d/%d\n", $w->getProperty("token")->isModuleInternal(), $w->getProperty("name")->isModuleInternal());
printf("const H/S:  %d/%d\n", $w->getReflectionConstant("HIDDEN")->isModuleInternal(), $w->getReflectionConstant("SHOWN")->isModuleInternal());

// isModuleInternal is false for ordinary (non-module) symbols.
class Plain { public function m() {} }
printf("plain:      %d\n", (new ReflectionClass("Plain"))->isModuleInternal());
?>
--EXPECT--
name:       Shop
classes:    Shop::Cart,Shop::Ledger,Shop::Widget
interfaces: Shop::Payable
enums:      Shop::Status
modules:    Shop::Sub
functions:  Shop::calc,Shop::log
constants:  {"VERSION":"1.2.3","SECRET":42}
vis Cart:   public
vis Ledger: internal
class L/C:  1/0
method s/o: 1/0
prop t/n:   1/0
const H/S:  1/0
plain:      0
