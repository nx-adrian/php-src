--TEST--
Modules: a membership file may combine module-root members with a sub-namespace by wrapping the root members in a bracketed global "namespace { }" block; everything lands under the module and claimed visibility applies
--DESCRIPTION--
A `module M;` membership file cannot mix loose root-level declarations with a
bracketed `namespace Sub { }` block (PHP requires a namespace declaration to be
first). The supported shape is to wrap the module-root members in a bracketed
global `namespace { }` block alongside the `namespace Sub { }` block. Root members
resolve as `M::C`, sub-namespace members as `M::Sub\D` (module boundary "::",
intra-module namespace "\"), and visibility comes entirely from the module's
forward-declarations (claims) in the definition block — not from the member file.
--FILE--
<?php
$dir = __DIR__ . '/mfnb_tmp';
@mkdir($dir);

// Module definition (Tier-1): claims only. Bodies live in the member file.
file_put_contents("$dir/Shop.def.php", <<<'PHP'
<?php
module Shop {
    public Widget;        // claimed public (module-root member)
    internal Gadget;      // claimed internal (module-root member)
    public Sub\Deep;      // claimed public (namespaced member)
}
PHP);

// Member file (Tier-2) using the bracketed-namespace shape: root members in a
// global `namespace { }`, plus a real sub-namespace `namespace Sub { }`.
file_put_contents("$dir/Shop.bodies.php", <<<'PHP'
<?php
module Shop;
namespace {
    class Widget { public function tag(): string { return "widget"; } }
    class Gadget { public function tag(): string { return "gadget"; } }
}
namespace Sub {
    class Deep { public function tag(): string { return "deep"; } }
}
PHP);

spl_autoload_register(function ($name) use ($dir) {
    if (!str_contains($name, 'Shop')) return;
    require_once "$dir/Shop.def.php";       // definition first (claims in place)
    require_once "$dir/Shop.bodies.php";    // then the bodies
});

// Everything below is cold: the autoloader loads the definition, then the bodies.
echo (new Shop::Widget)->tag(), "\n";              // root member -> Shop::Widget
echo (new Shop::Sub\Deep)->tag(), "\n";            // sub-namespace member -> Shop::Sub\Deep
echo Shop::Widget::class, "\n";                    // canonical name of the root member
echo Shop::Sub\Deep::class, "\n";                  // canonical name of the namespaced member

$rm = new ReflectionModule('Shop');
echo $rm->getSymbolVisibility('Shop::Widget'), "\n";   // public (from the claim)
echo $rm->getSymbolVisibility('Shop::Gadget'), "\n";   // internal (from the claim)
echo $rm->getSymbolVisibility('Shop::Sub\Deep'), "\n"; // public (from the claim)

// Visibility is enforced from outside per the claim, regardless of the member file shape.
try { new Shop::Gadget(); } catch (\Error $e) { echo $e->getMessage(), "\n"; }

// The members are NOT in the global namespace.
var_dump(class_exists('Widget'));
var_dump(class_exists('Sub\Deep'));
?>
--CLEAN--
<?php
$dir = __DIR__ . '/mfnb_tmp';
@array_map('unlink', glob("$dir/*"));
@rmdir($dir);
?>
--EXPECT--
widget
deep
Shop::Widget
Shop::Sub\Deep
public
internal
public
Cannot access internal module member "Shop::Gadget" from outside its module
bool(false)
bool(false)
