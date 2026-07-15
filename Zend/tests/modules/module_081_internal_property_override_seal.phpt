--TEST--
Modules: an `internal` property is sealed to the outside, like an `internal` method -- an external subclass may not override it
--DESCRIPTION--
An `internal` property is virtual within its module but sealed to the outside: a
subclass in another module (or in none) may not override it, which would let outside
code replace a member the module keeps private. A subclass inside the same module may
override it normally. A subclass outside the module still inherits the property (its
in-module methods keep working through it), it simply cannot redeclare it -- and direct
access from outside stays gated, as always.
--FILE--
<?php
module M {
    public class Base { internal int $v = 5; public function read(): int { return $this->v; } }
    public class Child extends Base { internal int $v = 9; public function read2(): int { return $this->v; } }
}
echo (new M::Child)->read2(), "\n";                 // same-module override: allowed
echo (new M::Child)->read(), "\n";                  // parent's in-module reader still works

module P { public class B { internal int $w = 3; public function r(): int { return $this->w; } } }
class Ext extends P::B {}                            // outside subclass, no override
echo (new Ext)->r(), "\n";                           // inherited internal property, read via in-module method
try { echo (new Ext)->w; } catch (\Error $e) { echo $e->getMessage(), "\n"; }  // outside direct access denied

function run(string $label, string $code): void {
    $f = tempnam(sys_get_temp_dir(), 'ips') . '.php';
    file_put_contents($f, "<?php\n" . $code);
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($f) . ' 2>&1');
    @unlink($f);
    echo $label, ": ", str_contains($out, 'Cannot override internal property') ? "sealed" : ("UNEXPECTED: " . trim($out)), "\n";
}

echo "-- seal --\n";
run('global-scope override', 'module M { public class Base { internal int $v = 1; } } class Child extends M::Base { public int $v = 2; }');
run('other-module override',  'module M { public class Base { internal int $v = 1; } } module N { public class Child extends M::Base { public int $v = 2; } }');
run('override keeping internal', 'module M { public class Base { internal int $v = 1; } } module N { public class Child extends M::Base { internal int $v = 2; } }');
?>
--EXPECT--
9
9
3
Cannot access internal module property Ext::$w from outside its module
-- seal --
global-scope override: sealed
other-module override: sealed
override keeping internal: sealed
