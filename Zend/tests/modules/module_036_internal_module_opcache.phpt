--TEST--
Modules: an internal nested module stays enforced across an opcache cache hit
--SKIPIF--
<?php
if (!extension_loaded('Zend OPcache')) die('skip requires opcache');
?>
--FILE--
<?php
/* The internal-module marker lives on the nested backing class's ce_flags2, which
 * opcache persists like any class flag. Verify enforcement still fires on a second
 * process that loads the module from opcache's file cache (compiler skipped). */
$php = getenv('TEST_PHP_EXECUTABLE');
$dir = __DIR__ . '/oc_imod_tmp';
@mkdir($dir);
$cache = $dir . '/cache';
@mkdir($cache);

file_put_contents($dir . '/mod.inc', <<<'PHP'
<?php
module Outer {
    internal module Inner {
        public class Gadget { public function who(): string { return "g"; } }
    }
}
PHP);
file_put_contents($dir . '/consumer.php', <<<'PHP'
<?php
require __DIR__ . '/mod.inc';
try { new Outer::Inner::Gadget(); echo "LEAKED\n"; }
catch (\Error $e) { echo $e->getMessage(), "\n"; }
PHP);

$opts = '-d opcache.enable_cli=1 -d opcache.file_cache=' . escapeshellarg($cache)
      . ' -d opcache.file_cache_only=1 -d opcache.validate_timestamps=0'
      . ' -d opcache.file_update_protection=0';
$cmd = escapeshellarg($php) . ' -n ' . $opts . ' ' . escapeshellarg($dir . '/consumer.php') . ' 2>&1';
echo "run1: ", trim(shell_exec($cmd)), "\n";
echo "run2: ", trim(shell_exec($cmd)), "\n";
?>
--CLEAN--
<?php
function rrmdir(string $d): void {
    if (!file_exists($d)) return;
    if (!is_dir($d)) { @unlink($d); return; }
    foreach (scandir($d) as $f) {
        if ($f === '.' || $f === '..') continue;
        rrmdir($d . '/' . $f);
    }
    @rmdir($d);
}
rrmdir(__DIR__ . '/oc_imod_tmp');
?>
--EXPECT--
run1: Cannot access internal module member "Outer::Inner::Gadget" from outside its module
run2: Cannot access internal module member "Outer::Inner::Gadget" from outside its module
