--TEST--
Modules: internal constants enforce across all fetch routes (fold, VM, constant()); reflection bypasses
--FILE--
<?php
module Billing {
    internal const SECRET = 42;
    public const OPEN = 1;

    public class Reader {
        public function read(): int { return module::SECRET; }         // inside a member class
    }
}

// Allowed from inside the module
echo (new Billing::Reader())->read(), "\n";         // 42
echo Billing::OPEN, "\n";                            // 1 (public, from outside)

// Denied from outside — static reference (compile-time fold refused -> runtime VM gate)
echo "static: ";
try { echo Billing::SECRET; } catch (\Error $e) { echo $e->getMessage(), "\n"; }

// Denied from outside — dynamic fetch via constant()
echo "dynamic: ";
try { echo constant("Billing::SECRET"); } catch (\Error $e) { echo $e->getMessage(), "\n"; }

// Reflection bypasses internal, exactly as it bypasses private
echo "reflection: ", (new ReflectionClassConstant("Billing", "SECRET"))->getValue(), "\n";
?>
--EXPECT--
42
1
static: Cannot access internal module constant Billing::SECRET from outside its module
dynamic: Cannot access internal module constant Billing::SECRET from outside its module
reflection: 42
