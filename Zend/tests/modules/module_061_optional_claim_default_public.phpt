--TEST--
Modules: a body-less claim with no visibility keyword defaults to public
--DESCRIPTION--
Optional visibility applies to claims as well as inline members: inside a module block a
bare `Widget;` claim (no public/internal keyword) forward-declares a *public* split-file
member, reachable from outside once its body loads. `internal` must still be written
explicitly, and an internal claim stays gated. Mirrors module_038, which uses explicit
claims.
--FILE--
<?php
$dir = __DIR__ . '/claims_opt_tmp';
@mkdir($dir);

file_put_contents($dir . '/def.php', <<<'PHP'
<?php
module Shop {
    Widget;              // bare claim -> public
    internal Secret;     // explicit internal claim
}
PHP);
file_put_contents($dir . '/widget.php', <<<'PHP'
<?php
module Shop;
class Widget { public function tag(): string { return "widget"; } }
PHP);
file_put_contents($dir . '/secret.php', <<<'PHP'
<?php
module Shop;
class Secret {}
PHP);

require $dir . '/def.php';
require $dir . '/widget.php';
require $dir . '/secret.php';

echo (new Shop::Widget)->tag(), "\n";                                            // public -> reachable
$r = new ReflectionModule("Shop");
echo $r->getSymbolVisibility("Shop::Widget"), " ", $r->getSymbolVisibility("Shop::Secret"), "\n";

try { new Shop::Secret(); echo "LEAKED\n"; }
catch (\Error $e) { echo $e->getMessage(), "\n"; }
?>
--CLEAN--
<?php
$dir = __DIR__ . '/claims_opt_tmp';
@array_map('unlink', glob($dir . '/*'));
@rmdir($dir);
?>
--EXPECT--
widget
public internal
Cannot access internal module member "Shop::Secret" from outside its module
