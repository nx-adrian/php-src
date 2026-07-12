--TEST--
Modules: internal enforcement holds even when the module is reached via autoload
--FILE--
<?php
$dir = __DIR__ . '/mod010';
@mkdir($dir . '/src', 0777, true);
file_put_contents($dir . '/src/Bank.php', <<<'PHP'
<?php
module Bank {
    public class Account {}
    internal class Vault {}
}
PHP);

spl_autoload_register(function (string $name) use ($dir): void {
    // "Bank" and "Bank::..." both map to src/Bank.php via the bare module name.
    $mod = explode('::', $name)[0];
    $file = $dir . '/src/' . str_replace('\\', '/', $mod) . '.php';
    if (is_file($file)) require $file;
});

// Public member, autoloaded: fine.
echo (new Bank::Account())::class, "\n";

// Internal member, module reached purely via autoload (not known when this file
// was compiled): still blocked by the runtime check.
try {
    new Bank::Vault();
    echo "NOT BLOCKED\n";
} catch (\Error $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
?>
--CLEAN--
<?php
$dir = __DIR__ . '/mod010';
@unlink($dir . '/src/Bank.php');
@rmdir($dir . '/src');
@rmdir($dir);
?>
--EXPECT--
Bank::Account
Error: Cannot access internal module member "Bank::Vault" from outside its module
