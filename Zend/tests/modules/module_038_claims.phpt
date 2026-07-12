--TEST--
Modules: body-less claims declare split-file members' visibility; bodies inherit it
--FILE--
<?php
$dir = __DIR__ . '/claims_tmp';
@mkdir($dir);

// Definition file: an inline member plus two body-less claims (one public, one
// internal and namespaced). The claim is where a split-file member's visibility lives.
file_put_contents($dir . '/def.php', <<<'PHP'
<?php
module Shop {
    public class Cart { public function tag(): string { return "cart"; } }
    public GuestUser;
    public Service;
    internal Auth\PasswordChecker;
}
PHP);

// Membership sub-files providing the bodies (a plain class has no visibility keyword).
file_put_contents($dir . '/guest.php', <<<'PHP'
<?php
module Shop;
class GuestUser { public function tag(): string { return "guest"; } }
PHP);
file_put_contents($dir . '/pwd.php', <<<'PHP'
<?php
module Shop;
namespace Auth;
class PasswordChecker { public function ok(): bool { return true; } }
PHP);
file_put_contents($dir . '/svc.php', <<<'PHP'
<?php
module Shop;
class Service {
    public function check(): string {
        // Inside the module: the internal claimed member is reachable.
        return (new \Shop::Auth\PasswordChecker)->ok() ? "ok-inside" : "no";
    }
}
PHP);

// The two-tier autoloader (or, here, an in-order require) loads the definition — and
// thus the claims — before each membership body compiles.
require $dir . '/def.php';
require $dir . '/guest.php';
require $dir . '/pwd.php';
require $dir . '/svc.php';

echo (new Shop::Cart)->tag(), "\n";        // inline member
echo (new Shop::GuestUser)->tag(), "\n";   // public claim -> reachable
echo (new Shop::Service)->check(), "\n";   // sibling inside the module reaches the internal claim

// The internal claimed member is denied from outside the module.
try { new Shop::Auth\PasswordChecker(); echo "LEAKED\n"; }
catch (\Error $e) { echo $e->getMessage(), "\n"; }
?>
--CLEAN--
<?php
$dir = __DIR__ . '/claims_tmp';
@array_map('unlink', glob($dir . '/*'));
@rmdir($dir);
?>
--EXPECT--
cart
guest
ok-inside
Cannot access internal module member "Shop::Auth\PasswordChecker" from outside its module
