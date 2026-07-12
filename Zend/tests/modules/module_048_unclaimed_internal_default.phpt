--TEST--
Modules: an unclaimed split-file symbol is internal by default (module-scoped, not public)
--FILE--
<?php
$dir = __DIR__ . '/uc48_tmp';
@mkdir($dir);

// Definition claims only Api (public). Helper is defined in a membership file but
// never claimed -> internal by default.
file_put_contents("$dir/def.php", <<<'PHP'
<?php
module Shop {
    public Api;
    public Sibling;
}
PHP);

file_put_contents("$dir/api.php", <<<'PHP'
<?php
module Shop;
class Api {
    public function useHelperIntraFile(): string { return (new Helper())->tag; }   // same file: fine
}
class Helper { public string $tag = 'helper'; }                                     // UNCLAIMED -> internal
PHP);

file_put_contents("$dir/sibling.php", <<<'PHP'
<?php
module Shop;
class Sibling {
    public function reachHelper(): string { return (new Shop::Helper())->tag; }     // same module: allowed
    public function reachViaScope(): void { /* module::Helper would be a compile error: not a member */ }
}
PHP);

require "$dir/def.php";
require "$dir/api.php";
require "$dir/sibling.php";

// The unclaimed class exists under its canonical name.
var_dump(class_exists('Shop::Helper', false));

// Intra-file use works.
echo (new Shop::Api)->useHelperIntraFile(), "\n";

// Reachable from another file of the SAME module (internal = module-scoped).
echo (new Shop::Sibling)->reachHelper(), "\n";

// Denied from OUTSIDE the module.
try { new Shop::Helper(); echo "LEAKED\n"; }
catch (\Error $e) { echo $e->getMessage(), "\n"; }
?>
--CLEAN--
<?php
$dir = __DIR__ . '/uc48_tmp';
@array_map('unlink', glob("$dir/*"));
@rmdir($dir);
?>
--EXPECT--
bool(true)
helper
helper
Cannot access internal module member "Shop::Helper" from outside its module
