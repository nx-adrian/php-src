--TEST--
Modules: body-less nested-module claim ("public module Inner;") + split definition; chained membership blocked
--FILE--
<?php
$dir = __DIR__ . '/nmc_tmp';
@mkdir($dir);

// Outer's definition claims two nested modules whose definitions live in their own files.
file_put_contents("$dir/def.php", <<<'PHP'
<?php
module Outer {
    public class Widget {}
    public module Pub;
    internal module Priv;
}
PHP);
// Each nested module is DEFINED in its own file, named by its "::" canonical boundary.
file_put_contents("$dir/pub.php", <<<'PHP'
<?php
module Outer::Pub { public class Gadget { public function w(): string { return "gadget"; } } }
PHP);
file_put_contents("$dir/priv.php", <<<'PHP'
<?php
module Outer::Priv {
    public class Secret { public function s(): string { return "secret"; } }
}
PHP);

require "$dir/def.php";
require "$dir/pub.php";
require "$dir/priv.php";

$r = new ReflectionModule("Outer");
echo "modules: ", implode(",", $r->getModules()), "\n";
echo "vis Pub:  ", $r->getSymbolVisibility("Outer::Pub"), "\n";
echo "vis Priv: ", $r->getSymbolVisibility("Outer::Priv"), "\n";

// Public nested module: reachable from outside.
echo "pub: ", (new Outer::Pub::Gadget)->w(), "\n";

// Internal nested module (visibility inherited from the "internal module Priv;" claim
// onto the split-file definition): its members are hidden from outside Outer.
try { new Outer::Priv::Secret(); echo "LEAKED\n"; }
catch (\Error $e) { echo "priv outside: denied\n"; }

// Chained membership is rejected — join a nested module with "module Outer::X;".
$out = shell_exec(getenv('TEST_PHP_EXECUTABLE') . ' -n -r ' . escapeshellarg('module A; module B; class C {}') . ' 2>&1');
echo "chained: ", (strpos($out, 'already declares') !== false ? 'blocked' : trim($out)), "\n";
?>
--CLEAN--
<?php
$dir = __DIR__ . '/nmc_tmp';
@array_map('unlink', glob("$dir/*"));
@rmdir($dir);
?>
--EXPECT--
modules: Outer::Pub,Outer::Priv
vis Pub:  public
vis Priv: internal
pub: gadget
priv outside: denied
chained: blocked
