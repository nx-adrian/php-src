--TEST--
Modules: Module::Member::class folds to the canonical name in a constant expression
--DESCRIPTION--
`Module::Member::class` is a compile-time name literal that yields the canonical string
("M::C"). It already worked in runtime expressions; this covers the constant-expression path
(class constants, top-level const), where it must fold identically instead of being rejected
as a generic "(expression)::class". Identity is not gated, so it also works for an internal
member. A genuine (expression)::class remains a compile error.
--FILE--
<?php
module M {
    class C { const X = "well hello there"; }
    internal class D {}
}

class A {
    const B = M::C::class;         // -> "M::C"
    const P = (M::C)::class;       // parenthesised, identical
    const I = M::D::class;         // internal member: identity, folds to "M::D"
}
const TOP = M::C::class;

echo A::B, "\n";
echo A::P, "\n";
echo A::I, "\n";
echo TOP, "\n";

// The chained constant walk composes: A::B is "M::C", then ::X reads C's constant.
echo A::B::X, "\n";
?>
--EXPECT--
M::C
M::C
M::D
M::C
well hello there
