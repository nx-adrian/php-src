--TEST--
Modules: a preloaded module enforces internal per request (CE-resident, registry-free)
--EXTENSIONS--
opcache
--INI--
opcache.enable_cli=1
opcache.preload={PWD}/module_029_preload.inc
--SKIPIF--
<?php
if (substr(PHP_OS, 0, 3) == 'WIN') die('skip not for Windows');
?>
--FILE--
<?php
/* Vendor\App is PRELOADED. This file never requires it, so the runtime
 * ZEND_DECLARE_MODULE op never runs and the per-request module registry is empty —
 * enforcement therefore proves it runs entirely off the persisted class entries. */
echo class_exists("Vendor\\App") ? "present\n" : "absent\n";
echo (new Vendor\App::Service())->ok(), "\n";
echo (new ReflectionModule("Vendor\\App"))->getName(), "\n";
echo Vendor\App::OPEN, "\n";

$c = "Vendor\\App::Secret";
try { new $c(); echo "LEAK class\n"; } catch (\Throwable $e) { echo "blocked class\n"; }
try { echo Vendor\App::HIDDEN; echo "LEAK const\n"; } catch (\Throwable $e) { echo "blocked const\n"; }
?>
--EXPECT--
present
svc
Vendor\App
1
blocked class
blocked const
