--TEST--
Modules: cold-autoload static-access chains (Module::Member::CONST/method/prop/::class) resolve
--FILE--
<?php
$dir = __DIR__ . '/csc_tmp';
@mkdir($dir);

// Module definition (Tier-1 target) with inline members, a nested module, a split-file
// member, and an internal member. Written to disk so it is reachable ONLY via autoload.
file_put_contents("$dir/Shop.def.php", <<<'PHP'
<?php
module Shop {
    public const VERSION = '3.0';
    public class Money {
        public const CURRENCY = 'USD';
        public static int $scale = 2;
        public static function tag(): string { return 'money'; }
    }
    internal class Secret { public const KEY = 'shhh'; }
    public module Tax {
        public class Rate {
            public const STANDARD = '0.20';
            public static function label(): string { return 'vat'; }
        }
    }
    public Line;
}
PHP);
file_put_contents("$dir/Shop.Line.php", "<?php\nmodule Shop;\nclass Line { public const KIND = 'line'; public static function make(): string { return 'made'; } }\n");

spl_autoload_register(function ($name) use ($dir) {
    $map = ['Shop' => "$dir/Shop.def.php", 'Shop\\Line' => "$dir/Shop.Line.php"];
    if (isset($map[$name]) && is_file($map[$name])) require $map[$name];
});

// This whole file compiled before the autoloader fired, so every chain below is cold.
echo Shop::VERSION, "\n";                 // 2-segment control
echo Shop::Money::CURRENCY, "\n";         // member class constant
echo Shop::Money::tag(), "\n";            // member static method
echo Shop::Money::$scale, "\n";           // member static property
echo Shop::Money::class, "\n";            // ::class on a chain
echo Shop::Tax::Rate::STANDARD, "\n";     // depth-3 nested member constant
echo Shop::Tax::Rate::label(), "\n";      // depth-3 nested static method
echo Shop::Line::KIND, "\n";              // split-file member (two-tier autoload) via chain
echo Shop::Line::make(), "\n";
echo Shop::Secret::class, "\n";           // identity of an internal member is ungated

// Internal member reached via a chain from outside: denied cleanly.
try { echo Shop::Secret::KEY; } catch (\Error $e) { echo $e->getMessage(), "\n"; }
// A genuine typo (undefined member) is reported as such, not silently resolved.
try { echo Shop::Nope::X; } catch (\Error $e) { echo $e->getMessage(), "\n"; }
?>
--CLEAN--
<?php
$dir = __DIR__ . '/csc_tmp';
@array_map('unlink', glob("$dir/*"));
@rmdir($dir);
?>
--EXPECT--
3.0
USD
money
2
Shop::Money
0.20
vat
line
made
Shop::Secret
Cannot access internal module member "Shop::Secret" from outside its module
"Nope" is not a member of module "Shop"
