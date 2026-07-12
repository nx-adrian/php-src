--TEST--
Modules: backing-class constants survive an opcache cache hit
--SKIPIF--
<?php
if (!extension_loaded('Zend OPcache')) die('skip requires opcache');
?>
--FILE--
<?php
/* The backing class is an ordinary class entry, so opcache persists its constants
 * with the normal class machinery. Verify a module constant is still reachable on
 * a second process that loads the module file from opcache's file cache (compiler
 * skipped). */
$php = getenv('TEST_PHP_EXECUTABLE');
$dir = __DIR__ . '/oc_const_tmp';
@mkdir($dir);
$cache = $dir . '/cache';
@mkdir($cache);

file_put_contents($dir . '/mod.inc', <<<'PHP'
<?php
module Vendor\App {
    public const VERSION = "1.2.3";
}
PHP);
file_put_contents($dir . '/consumer.php', <<<'PHP'
<?php
require __DIR__ . '/mod.inc';
echo Vendor\App::VERSION, "\n";
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
$dir = __DIR__ . '/oc_const_tmp';
foreach (glob($dir . '/cache/*/*/*') as $f) @unlink($f);
foreach (glob($dir . '/cache/*/*') as $d) @rmdir($d);
foreach (glob($dir . '/cache/*') as $d) @rmdir($d);
@rmdir($dir . '/cache');
@unlink($dir . '/mod.inc');
@unlink($dir . '/consumer.php');
@rmdir($dir);
?>
--EXPECT--
run1: 1.2.3
run2: 1.2.3
