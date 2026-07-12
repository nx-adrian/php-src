--TEST--
Modules: static-access chains reach a NAMESPACED member (Module::Ns\Member::CONST/method/prop/::class)
--FILE--
<?php
$dir = __DIR__ . '/nsm_tmp';
@mkdir($dir);
@mkdir("$dir/Vendor");
@mkdir("$dir/Vendor/User");
@mkdir("$dir/Vendor/User/Auth");

// Module definition claims two namespaced members (public + internal), split into files.
file_put_contents("$dir/Vendor/User.php", "<?php\nmodule Vendor\\User {\n    public Auth\\Checker;\n    internal Auth\\Secret;\n}\n");
file_put_contents("$dir/Vendor/User/Auth/Checker.php", "<?php\nmodule Vendor\\User;\nnamespace Auth;\nclass Checker { const OK = 'checker-ok'; public static int \$count = 41; public static function run(): string { return 'ran'; } }\n");
file_put_contents("$dir/Vendor/User/Auth/Secret.php", "<?php\nmodule Vendor\\User;\nnamespace Auth;\nclass Secret { const KEY = 'secret'; }\n");

spl_autoload_register(function ($name) use ($dir) {
    $f = "$dir/" . str_replace('\\', '/', $name) . '.php';
    if (is_file($f)) require $f;
});

// Cold: this file compiled before the module loaded; the member name is namespaced
// (Auth\Checker). Reference forms below were parse errors before the grammar fix.
echo Vendor\User::Auth\Checker::OK, "\n";        // namespaced member constant
echo Vendor\User::Auth\Checker::run(), "\n";     // namespaced member static method
echo Vendor\User::Auth\Checker::$count, "\n";    // namespaced member static property
echo Vendor\User::Auth\Checker::class, "\n";     // ::class on a namespaced member
echo (new Vendor\User::Auth\Checker()) instanceof Vendor\User::Auth\Checker ? "instanceof-ok\n" : "no\n";

// Internal namespaced member: identity ungated, use denied from outside.
echo Vendor\User::Auth\Secret::class, "\n";
try { echo Vendor\User::Auth\Secret::KEY; }
catch (\Error $e) { echo $e->getMessage(), "\n"; }
?>
--CLEAN--
<?php
$dir = __DIR__ . '/nsm_tmp';
@array_map('unlink', glob("$dir/Vendor/User/Auth/*"));
@array_map('unlink', glob("$dir/Vendor/User/*.php"));
@array_map('unlink', glob("$dir/Vendor/*.php"));
@rmdir("$dir/Vendor/User/Auth"); @rmdir("$dir/Vendor/User"); @rmdir("$dir/Vendor"); @rmdir($dir);
?>
--EXPECT--
checker-ok
ran
41
Vendor\User::Auth\Checker
instanceof-ok
Vendor\User::Auth\Secret
Cannot access internal module member "Vendor\User::Auth\Secret" from outside its module
