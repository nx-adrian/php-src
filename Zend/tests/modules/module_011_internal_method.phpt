--TEST--
Modules: internal class methods are callable across a module but not from outside
--FILE--
<?php
module OrderManagement {
    public class Order {
        public function ship(): string {
            // A different class in the same module may call the internal method.
            $inv = new OrderManagement::InventoryEngine();
            return $inv->reserveStock();
        }
    }
    public class InventoryEngine {
        public function getStatus(): string { return "Active"; }
        internal function reserveStock(): string { return "reserved"; }
        internal static function audit(): string { return "audited"; }
    }
}

$order = new OrderManagement::Order();
echo $order->ship(), "\n";                 // internal instance call from sibling: allowed

$engine = new OrderManagement::InventoryEngine();
echo $engine->getStatus(), "\n";           // public: allowed

// internal instance method from outside the module: denied
try {
    $engine->reserveStock();
} catch (\Error $e) {
    echo $e->getMessage(), "\n";
}

// internal static method from outside the module: denied. (Chained "A::B::C()"
// syntax is deferred to a later increment, so reach the class dynamically.)
$cls = 'OrderManagement::InventoryEngine';
try {
    $cls::audit();
} catch (\Error $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
reserved
Active
Cannot call internal method OrderManagement::InventoryEngine::reserveStock() from outside its module
Cannot call internal method OrderManagement::InventoryEngine::audit() from outside its module
