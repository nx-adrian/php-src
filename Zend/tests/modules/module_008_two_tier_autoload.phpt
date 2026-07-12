--TEST--
Modules: two-tier autoload (module manifest, then "::"->"\" sub-file) with a PSR-4-style loader
--FILE--
<?php
$dir = __DIR__ . '/mod008';
@mkdir($dir . '/src/User/Auth', 0777, true);

// Manifest: module Vendor\User maps to src/User.php; Profile is defined inline.
file_put_contents($dir . '/src/User.php', <<<'PHP'
<?php
module Vendor\User {
    public class Profile {
        public function __construct(public string $name = "anon") {}
    }
    public Auth\PasswordChecker;   // claim the split-file member public so it is reachable from outside
}
PHP);

// Membership sub-file: Vendor\User\Auth\PasswordChecker -> src/User/Auth/PasswordChecker.php
file_put_contents($dir . '/src/User/Auth/PasswordChecker.php', <<<'PHP'
<?php
module Vendor\User;
namespace Auth;
class PasswordChecker {
    public function tag(): string { return "checker"; }
}
PHP);

// Standard PSR-4-style autoloader: maps "Vendor\..." (module OR backslash form)
// onto files. Needs no module-specific logic.
spl_autoload_register(function (string $name) use ($dir): void {
    if (!str_starts_with($name, 'Vendor\\')) return;
    $rel = str_replace('\\', '/', substr($name, strlen('Vendor\\')));
    $file = $dir . '/src/' . $rel . '.php';
    echo "[autoload] $name\n";
    if (is_file($file)) require $file;
});

// Tier 1: resolved from the inline manifest definition.
$p = new Vendor\User::Profile("adrian");
echo $p->name, " ", $p::class, "\n";

// Tier 2: resolved from the separate membership sub-file.
$c = new Vendor\User::Auth\PasswordChecker();
echo $c->tag(), " ", $c::class, "\n";
?>
--CLEAN--
<?php
$dir = __DIR__ . '/mod008';
@unlink($dir . '/src/User/Auth/PasswordChecker.php');
@unlink($dir . '/src/User.php');
@rmdir($dir . '/src/User/Auth');
@rmdir($dir . '/src/User/Auth');
@rmdir($dir . '/src/User');
@rmdir($dir . '/src');
@rmdir($dir);
?>
--EXPECT--
[autoload] Vendor\User
adrian Vendor\User::Profile
[autoload] Vendor\User\Auth\PasswordChecker
checker Vendor\User::Auth\PasswordChecker
