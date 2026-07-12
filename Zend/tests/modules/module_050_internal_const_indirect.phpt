--TEST--
Modules: internal enforcement holds for class constants via every route (direct, constant(), const-walk)
--DESCRIPTION--
A public constant of an internal type must not leak through indirect resolution: the
constant() function, or a chained "A::PATH::CONST" where an intermediate constant holds
the internal type's name. Public chains still resolve (consistent with PHP); only the
boundary is enforced. Same-module access is unaffected.
--FILE--
<?php
module M {
    public class Pub { public const K = "pubK"; }
    internal class Sec { public const H = "secH"; }
    public const PUBPATH = "M::Pub";
    public const SECPATH = "M::Sec";   // a PUBLIC const holding an internal type's name
    public class Api {
        public static function insideDirect(): string { return Sec::H; }
        public static function insideWalk(): string { $c = module::SECPATH; return $c::H; }
    }
}
class X { const Y = 'Z'; }
class Z { const W = "zw"; }

function deny(string $label, callable $fn): void {
    try { $fn(); echo "LEAK: $label\n"; }
    catch (\Error $e) { echo "denied: $label\n"; }
}

// Public resolves — direct, const-walk, and plain (non-module) chains.
echo M::Pub::K, "\n";
echo M::PUBPATH::K, "\n";
echo X::Y::W, "\n";

// Same-module internal access works.
echo M::Api::insideDirect(), "\n";
echo M::Api::insideWalk(), "\n";

// Internal denied from outside through every route.
deny('direct M::Sec::H',      fn() => M::Sec::H);
deny('const-walk M::SECPATH::H', fn() => M::SECPATH::H);
deny('constant("M::Sec::H")', fn() => constant("M::Sec::H"));
deny('dynamic $c::H',         function () { $c = "M::Sec"; return $c::H; });
?>
--EXPECT--
pubK
pubK
zw
secH
secH
denied: direct M::Sec::H
denied: const-walk M::SECPATH::H
denied: constant("M::Sec::H")
denied: dynamic $c::H
