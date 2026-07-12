--TEST--
Modules: "module::Member" resolves members declared later in the same block (forward reference)
--FILE--
<?php
module M {
    public class A {
        // Every reference below points at a member declared *after* A.
        public function makeB(): module::B { return new module::B(); }
        public function typeHint(module::C $c): string { return $c::LABEL; }
        public function throwIt(): void { throw new module::E("boom"); }
    }

    public class B { public string $v = 'B'; }

    public class C { const LABEL = 'C-label'; }

    public class E extends \RuntimeException {}
}

$a = new M::A;
echo $a->makeB()->v, "\n";
echo $a->typeHint(new M::C), "\n";
try { $a->throwIt(); } catch (M::E $e) { echo "caught: ", $e->getMessage(), "\n"; }
?>
--EXPECT--
B
C-label
caught: boom
