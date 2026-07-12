--TEST--
Modules: internal enforcement at runtime — dynamic references and autoloaded members
--FILE--
<?php
module Shop {
    public class Cart {
        // Internal access from inside the module is allowed, even dynamically.
        public function newLine(): object {
            $c = 'Shop::LineItem';
            return new $c();
        }
    }
    internal class LineItem {
        public function label(): string { return "line"; }
    }
}

// Inside-module dynamic internal access: allowed.
$cart = new Shop::Cart();
echo $cart->newLine()->label(), "\n";

// Dynamic "new \$name" of an internal member from OUTSIDE the module: blocked.
$name = 'Shop::LineItem';
try {
    new $name();
    echo "NOT BLOCKED\n";
} catch (\Error $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}

// Public members remain freely accessible, statically or dynamically.
$pub = 'Shop::Cart';
echo (new $pub())::class, "\n";
?>
--EXPECT--
line
Error: Cannot access internal module member "Shop::LineItem" from outside its module
Shop::Cart
