--TEST--
Modules: two-tier autoload resolves split-file members (top-level and nested; public and internal)
--FILE--
<?php
$dir = __DIR__ . '/al_tmp';
@mkdir($dir);

// Top-level module: definition (claims) + split-file bodies.
file_put_contents("$dir/Shop.def.php", "<?php\nmodule Shop {\n    public GuestUser;\n    internal Auth\\Checker;\n}\n");
file_put_contents("$dir/Shop.GuestUser.php", "<?php\nmodule Shop;\nclass GuestUser { public function t(): string { return 'guest'; } }\n");
file_put_contents("$dir/Shop.Auth.Checker.php", "<?php\nmodule Shop;\nnamespace Auth;\nclass Checker { public function ok(): bool { return true; } }\n");

// Nested module: definition (with nested claims) + split-file nested bodies.
file_put_contents("$dir/Outer.def.php", "<?php\nmodule Outer {\n    public module Inner {\n        public Gadget;\n        internal Secret;\n    }\n}\n");
file_put_contents("$dir/Outer.Inner.Gadget.php", "<?php\nmodule Outer;\nmodule Inner;\nclass Gadget { public function w(): string { return 'gadget'; } }\n");
file_put_contents("$dir/Outer.Inner.Secret.php", "<?php\nmodule Outer;\nmodule Inner;\nclass Secret { public function s(): string { return 'secret'; } }\n");

spl_autoload_register(function ($name) use ($dir) {
    $map = [
        'Shop'               => "$dir/Shop.def.php",
        'Shop\\GuestUser'    => "$dir/Shop.GuestUser.php",
        'Shop\\Auth\\Checker'=> "$dir/Shop.Auth.Checker.php",
        'Outer'              => "$dir/Outer.def.php",
        'Outer\\Inner\\Gadget' => "$dir/Outer.Inner.Gadget.php",
        'Outer\\Inner\\Secret' => "$dir/Outer.Inner.Secret.php",
    ];
    if (isset($map[$name]) && is_file($map[$name])) require $map[$name];
});

// Public members resolve cold, purely through the autoloader (nothing required yet).
echo (new Shop::GuestUser)->t(), "\n";
echo (new Outer::Inner::Gadget)->w(), "\n";

// Internal split-file members are denied from outside — the claim's visibility is
// applied because tier-1 loads the definition (claims) before tier-2 loads the body.
try { new Shop::Auth\Checker(); echo "LEAKED\n"; }
catch (\Error $e) { echo $e->getMessage(), "\n"; }
try { new Outer::Inner::Secret(); echo "LEAKED\n"; }
catch (\Error $e) { echo $e->getMessage(), "\n"; }
?>
--CLEAN--
<?php
$dir = __DIR__ . '/al_tmp';
@array_map('unlink', glob("$dir/*"));
@rmdir($dir);
?>
--EXPECT--
guest
gadget
Cannot access internal module member "Shop::Auth\Checker" from outside its module
Cannot access internal module member "Outer::Inner::Secret" from outside its module
