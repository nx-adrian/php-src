--TEST--
Modules: "module::Member" resolves forward references at any nesting depth
--FILE--
<?php
module M {
    public class A {
        // Direct members declared after A.
        public function makeB(): module::B { return new module::B(); }
        public function typeHint(module::C $c): string { return $c::LABEL; }
        public function throwIt(): void { throw new module::E("boom"); }

        // Reach into nested inline modules declared after A, at increasing depth.
        public function d1(): string { return module::Inner::Widget::TAG; }
        public function d2(): string { return module::Inner::Deep::Gizmo::TAG; }
        public function d3(): string { return module::Inner::Deep::Deeper::Q::TAG; }
    }

    public class B { public string $v = 'B'; }
    public class C { const LABEL = 'C-label'; }
    public class E extends \RuntimeException {}

    public module Inner {
        // Forward reference *within* the nested block, too.
        public class First {
            public function make(): module::Second { return new module::Second(); }
        }
        public class Second { public string $v = 'second'; }

        public class Widget { const TAG = 'w'; }
        public module Deep {
            public class Gizmo { const TAG = 'g'; }
            public module Deeper {
                public class Q { const TAG = 'q'; }
            }
        }
    }
}

$a = new M::A;
echo $a->makeB()->v, "\n";
echo $a->typeHint(new M::C), "\n";
try { $a->throwIt(); } catch (M::E $e) { echo "caught: ", $e->getMessage(), "\n"; }
echo $a->d1(), $a->d2(), $a->d3(), "\n";
echo (new M::Inner::First)->make()->v, "\n";
?>
--EXPECT--
B
C-label
caught: boom
wgq
second
