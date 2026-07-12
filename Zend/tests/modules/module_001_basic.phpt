--TEST--
Modules: manifest block declares classes under the canonical "::" boundary key
--FILE--
<?php
module Vendor\User {
    class Profile {
        public function __construct(public string $name) {}
    }
    class GuestUser {}
}

// Module-owned classes are reachable by their canonical FQN (single "::" at the
// module boundary), NOT by a plain namespace path.
var_dump(class_exists('Vendor\User::Profile'));
var_dump(class_exists('Vendor\User::GuestUser'));
var_dump(class_exists('Vendor\User\Profile'));

$c = 'Vendor\User::Profile';
$o = new $c('adrian');
var_dump($o->name);
echo $o::class, "\n";
echo get_class($o), "\n";
?>
--EXPECT--
bool(true)
bool(true)
bool(false)
string(6) "adrian"
Vendor\User::Profile
Vendor\User::Profile
