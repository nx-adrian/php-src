--TEST--
Modules: native "::" syntax in new, type hints, extends, and instanceof
--FILE--
<?php
module Vendor\User {
    public class Profile {
        public function __construct(public string $name) {}
    }
}

// new
$adrian = new Vendor\User::Profile("adrian");
var_dump($adrian->name);

// type hint (param + return)
function relabel(Vendor\User::Profile $p, string $n): Vendor\User::Profile {
    return new Vendor\User::Profile($n);
}
echo relabel($adrian, "mo")->name, "\n";

// extends
class LoudProfile extends Vendor\User::Profile {
    public function shout(): string { return strtoupper($this->name); }
}
$l = new LoudProfile("zed");
echo $l->shout(), "\n";

// instanceof (module-qualified, resolved by default-shift)
var_dump($l instanceof Vendor\User::Profile);
?>
--EXPECT--
string(6) "adrian"
mo
ZED
bool(true)
