--TEST--
Modules: a constant-walk (A::CONST::X, where CONST holds a class name) resolves from inside a member class via "module::", not just from outside
--DESCRIPTION--
"module::PATH::CONST" where PATH is a module constant whose value names a member
class must resolve the same way it does from outside the module (read the constant,
then use its value as the class), rather than being mis-resolved as a member class
literally named "M::PATH". Only the runtime access is gated: a walk to a public
member resolves everywhere; a walk to an internal member resolves from inside the
module but is denied from outside. Class-walks (middle segment IS a class) are
unaffected.
--FILE--
<?php
module M {
    public class Pub { public const K = "pubK"; }
    internal class Sec { public const H = "secH"; }

    public const PUBPATH = "M::Pub";   // public const holding a PUBLIC type's name
    public const SECPATH = "M::Sec";   // public const holding an INTERNAL type's name

    public class Api {
        // constant-walks from inside, via the module:: self-reference
        public static function walkPub(): string { return module::PUBPATH::K; }
        public static function walkSec(): string { return module::SECPATH::H; }
        // class-walk (middle segment is a real member class) still works
        public static function classWalk(): string { return module::Pub::K; }
    }
}

echo "-- from inside the module (member class) --\n";
echo "walkPub:   ", M::Api::walkPub(), "\n";     // pubK
echo "walkSec:   ", M::Api::walkSec(), "\n";     // secH (internal, but reached from inside)
echo "classWalk: ", M::Api::classWalk(), "\n";   // pubK

echo "\n-- from outside the module --\n";
echo "public walk M::PUBPATH::K: ", M::PUBPATH::K, "\n";   // pubK
try {
    $x = M::SECPATH::H;                                     // internal target -> denied
    echo "LEAK internal walk\n";
} catch (\Error $e) {
    echo "internal walk M::SECPATH::H: ", $e->getMessage(), "\n";
}
echo "class walk M::Pub::K: ", M::Pub::K, "\n";            // pubK
?>
--EXPECT--
-- from inside the module (member class) --
walkPub:   pubK
walkSec:   secH
classWalk: pubK

-- from outside the module --
public walk M::PUBPATH::K: pubK
internal walk M::SECPATH::H: Cannot access internal module member "M::Sec" from outside its module
class walk M::Pub::K: pubK
