--TEST--
Modules: static access reaches a namespaced member of a NESTED module (Outer::Inner::Ns\Member::CONST)
--FILE--
<?php
$dir = __DIR__ . '/nnm_tmp';
@mkdir($dir);
@mkdir("$dir/Outer");
@mkdir("$dir/Outer/Inner");
@mkdir("$dir/Outer/Inner/Auth");

// A nested module (Outer::Inner) whose member is namespaced (Auth\Checker), split-file.
file_put_contents("$dir/Outer.php", "<?php\nmodule Outer {\n    public module Inner {\n        public Auth\\Checker;\n        internal Auth\\Secret;\n    }\n}\n");
file_put_contents("$dir/Outer/Inner/Auth/Checker.php", "<?php\nmodule Outer::Inner;\nnamespace Auth;\nclass Checker { const OK = 'nested-ns-ok'; public static int \$count = 7; public static function run(): string { return 'ran'; } }\n");
file_put_contents("$dir/Outer/Inner/Auth/Secret.php", "<?php\nmodule Outer::Inner;\nnamespace Auth;\nclass Secret { const KEY = 'k'; }\n");

spl_autoload_register(function ($name) use ($dir) {
    $f = "$dir/" . str_replace('\\', '/', $name) . '.php';
    if (is_file($f)) require $f;
});

// Cold: simple nested-module segments (Outer::Inner) precede the qualified member.
echo Outer::Inner::Auth\Checker::OK, "\n";
echo Outer::Inner::Auth\Checker::run(), "\n";
echo Outer::Inner::Auth\Checker::$count, "\n";
echo Outer::Inner::Auth\Checker::class, "\n";
echo (new Outer::Inner::Auth\Checker()) instanceof Outer::Inner::Auth\Checker ? "instanceof-ok\n" : "no\n";

// Internal namespaced member of the nested module: identity ungated, use denied.
echo Outer::Inner::Auth\Secret::class, "\n";
try { echo Outer::Inner::Auth\Secret::KEY; }
catch (\Error $e) { echo $e->getMessage(), "\n"; }
?>
--CLEAN--
<?php
$dir = __DIR__ . '/nnm_tmp';
@array_map('unlink', glob("$dir/Outer/Inner/Auth/*"));
@array_map('unlink', glob("$dir/Outer/*.php"));
@array_map('unlink', glob("$dir/*.php"));
@rmdir("$dir/Outer/Inner/Auth"); @rmdir("$dir/Outer/Inner"); @rmdir("$dir/Outer"); @rmdir($dir);
?>
--EXPECT--
nested-ns-ok
ran
7
Outer::Inner::Auth\Checker
instanceof-ok
Outer::Inner::Auth\Secret
Cannot access internal module member "Outer::Inner::Auth\Secret" from outside its module
