--TEST--
Modules: internal-method flag must not alias strict_types (regression, bit 31)
--FILE--
<?php
declare(strict_types=1);

module M {
    public class S {
        public function pub(): string { return "pub"; }        // public: callable from anywhere
        internal function sec(): string { return "sec"; }       // internal: same-module only
        public function reach(): string { return $this->sec(); }
    }
}

$s = new M::S();
echo $s->pub(), "\n";                 // must NOT be blocked in a strict_types file
echo $s->reach(), "\n";               // internal reached from inside the module
try {
    $s->sec();                         // internal from outside: denied
    echo "LEAKED\n";
} catch (\Error $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
pub
sec
Cannot call internal method M::S::sec() from outside its module
