--TEST--
Modules: n-tier autoload loads each nested-module level (a nested module split into its own file)
--FILE--
<?php
$dir = __DIR__ . '/ntier_tmp';
@mkdir($dir);
@mkdir("$dir/Shop");
@mkdir("$dir/Shop/Tax");
@mkdir("$dir/Deep");
@mkdir("$dir/Deep/A");
@mkdir("$dir/Deep/A/B");

// --- Variant A: nested module Tax is CLAIMED by Shop and defined in its OWN file,
//     which in turn claims its members (split one level further). Each level lives in
//     a separate file, so tier-1's "load only the outermost" was not enough. ---
file_put_contents("$dir/Shop.php", "<?php\nmodule Shop {\n    public module Tax;\n}\n");
file_put_contents("$dir/Shop/Tax.php", "<?php\nmodule Shop::Tax {\n    public Rate;\n    internal Secret;\n}\n");
file_put_contents("$dir/Shop/Tax/Rate.php", "<?php\nmodule Shop::Tax;\nclass Rate { const STANDARD = '0.20'; public function hi(): string { return 'rate'; } }\n");
file_put_contents("$dir/Shop/Tax/Secret.php", "<?php\nmodule Shop::Tax;\nclass Secret { const KEY = 'k'; }\n");

// --- Variant B: nested module defined in its own file with its member INLINE. ---
file_put_contents("$dir/Deep.php", "<?php\nmodule Deep {\n    public module A;\n}\n");
file_put_contents("$dir/Deep/A.php", "<?php\nmodule Deep::A {\n    public module B;\n}\n");
file_put_contents("$dir/Deep/A/B.php", "<?php\nmodule Deep::A::B {\n    public class Cog { public function spin(): string { return 'spin'; } }\n}\n");

spl_autoload_register(function ($name) use ($dir) {
    $f = "$dir/" . str_replace('\\', '/', $name) . '.php';
    if (is_file($f)) require $f;
});

// Variant A: claimed+split public member resolves (its claim, in Shop/Tax.php, is loaded).
echo (new Shop::Tax::Rate())->hi(), "\n";
echo Shop::Tax::Rate::STANDARD, "\n";
echo Shop::Tax::Rate::class, "\n";

// The internal member of the split nested module is still denied from outside.
try { new Shop::Tax::Secret(); echo "LEAK\n"; }
catch (\Error $e) { echo $e->getMessage(), "\n"; }

// Variant B: a three-level nesting where the deepest member is inline in its module's file.
echo (new Deep::A::B::Cog())->spin(), "\n";
?>
--CLEAN--
<?php
$dir = __DIR__ . '/ntier_tmp';
@array_map('unlink', glob("$dir/Shop/Tax/*"));
@array_map('unlink', glob("$dir/Shop/*.php"));
@array_map('unlink', glob("$dir/Deep/A/B/*"));
@array_map('unlink', glob("$dir/Deep/A/*.php"));
@array_map('unlink', glob("$dir/Deep/*.php"));
@array_map('unlink', glob("$dir/*.php"));
@rmdir("$dir/Shop/Tax"); @rmdir("$dir/Shop");
@rmdir("$dir/Deep/A/B"); @rmdir("$dir/Deep/A"); @rmdir("$dir/Deep");
@rmdir($dir);
?>
--EXPECT--
rate
0.20
Shop::Tax::Rate
Cannot access internal module member "Shop::Tax::Secret" from outside its module
spin
