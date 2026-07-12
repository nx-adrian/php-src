--TEST--
Modules: registration is runtime-driven, so a module survives an opcache cache hit
--SKIPIF--
<?php
if (!extension_loaded('Zend OPcache')) die('skip requires opcache');
?>
--FILE--
<?php
/* The module is registered at COMPILE time for compile-time checks, but also via a
 * runtime ZEND_DECLARE_MODULE op whose member roster rides in the op_array's
 * constant table. opcache persists that op_array, so on a cache hit (compiler
 * skipped) the module + roster are still rebuilt. This test proves it by loading a
 * module file from opcache's file cache in a SECOND process, where the compiler
 * never runs. */
$php = getenv('TEST_PHP_EXECUTABLE');
$dir = __DIR__ . '/oc_persist_tmp';
@mkdir($dir);
$cache = $dir . '/cache';
@mkdir($cache);

file_put_contents($dir . '/mod.inc', <<<'PHP'
<?php
module Vendor\App {
    public class Service {}
    internal class Secret { public function ping() { return "secret"; } }
}
PHP);

file_put_contents($dir . '/consumer.php', <<<'PHP'
<?php
require __DIR__ . '/mod.inc';
$r = new ReflectionModule("Vendor\\App");
echo "module=", $r->getName(), " secret=", $r->getSymbolVisibility("Vendor\\App::Secret"), "\n";
$c = "Vendor\\App::Secret";              // dynamic name -> only runtime enforcement applies
try { (new $c())->ping(); echo "LEAKED\n"; }
catch (\Throwable $e) { echo "blocked\n"; }
PHP);

$opts = '-d opcache.enable_cli=1 -d opcache.file_cache=' . escapeshellarg($cache)
      . ' -d opcache.file_cache_only=1 -d opcache.validate_timestamps=0'
      . ' -d opcache.file_update_protection=0';
$cmd = escapeshellarg($php) . ' -n ' . $opts . ' ' . escapeshellarg($dir . '/consumer.php') . ' 2>&1';

echo "run1: ", trim(shell_exec($cmd)), "\n";   // fresh compile -> populates cache
echo "run2: ", trim(shell_exec($cmd)), "\n";   // mod.inc loaded from cache; compiler skipped
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
rrmdir(__DIR__ . '/oc_persist_tmp');
?>
--EXPECT--
run1: module=Vendor\App secret=internal
blocked
run2: module=Vendor\App secret=internal
blocked
