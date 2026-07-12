--TEST--
Modules: file-level membership declaration, with and without an inner namespace
--FILE--
<?php
require __DIR__ . '/module_002_member_ns.inc';
require __DIR__ . '/module_002_member_root.inc';

var_dump(class_exists('Vendor\User::Auth\PasswordChecker'));
var_dump(class_exists('Vendor\User::GuestUser'));
// The bare (non-module) namespace forms must not exist.
var_dump(class_exists('Auth\PasswordChecker'));
var_dump(class_exists('GuestUser'));

echo (new ('Vendor\User::Auth\PasswordChecker'))->tag(), "\n";
?>
--EXPECT--
bool(true)
bool(true)
bool(false)
bool(false)
checker
