--TEST--
Modules: ReflectionModule introspects a declared module
--FILE--
<?php
module Vendor\Shop {
    public class Cart {}
    public class Order {}
    internal class Ledger {}
}

$r = new ReflectionModule('Vendor\Shop');
echo $r->getName(), "\n";
echo $r->name, "\n";

$classes = $r->getClasses();
sort($classes);
var_dump($classes);

echo $r->getSymbolVisibility('Vendor\Shop::Cart'), "\n";
echo $r->getSymbolVisibility('Vendor\Shop::Ledger'), "\n";

// Unknown module -> ReflectionException
try {
    new ReflectionModule('Does\NotExist');
} catch (\ReflectionException $e) {
    echo $e->getMessage(), "\n";
}

// Unknown symbol -> ReflectionException
try {
    $r->getSymbolVisibility('Vendor\Shop::Nope');
} catch (\ReflectionException $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
Vendor\Shop
Vendor\Shop
array(3) {
  [0]=>
  string(17) "Vendor\Shop::Cart"
  [1]=>
  string(19) "Vendor\Shop::Ledger"
  [2]=>
  string(18) "Vendor\Shop::Order"
}
public
internal
Module "Does\NotExist" does not exist
Symbol "Vendor\Shop::Nope" is not a member of module "Vendor\Shop"
