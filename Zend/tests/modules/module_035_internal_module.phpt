--TEST--
Modules: an `internal` nested module is visible within its parent but hidden outside it
--FILE--
<?php
module Outer {
    // An internal member OF Outer (not of Inner): Inner's code must NOT reach it,
    // because scope membership is not transitive (Outer::Inner is not in Outer).
    internal class OuterSecret { public function s(): string { return "outer-secret"; } }

    internal module Inner {
        public const IV = "iv";
        public class Gadget { public function who(): string { return "gadget"; } }
        internal class Hidden { public function h(): string { return "hidden"; } }

        public class Api {
            public static function make(): string { return "made"; }

            // Within Inner, its own internal member is reachable.
            public static function reachHidden(): string { return (new module::Hidden)->h(); }

            // Inner reaching UP into Outer's own internal member must be denied.
            public static function reachUp(): string {
                try { return (new \Outer::OuterSecret)->s() . " LEAKED"; }
                catch (\Error $e) { return $e->getMessage(); }
            }
        }
    }

    // A sibling member of Outer (outside Inner) may reach Inner's PUBLIC members,
    // because Inner is internal to Outer, not to itself.
    public class Sibling {
        public function reach(): string {
            return module::Inner::IV . "/" . module::Inner::Api::make()
                 . "/" . (new module::Inner::Gadget)->who();
        }
    }

    public class Main {
        public static function fromOuter(): string {
            return (new module::Sibling)->reach() . "/" . module::Inner::Api::reachHidden();
        }
        // Driven from Outer's scope (which may see Inner); reachUp then runs in Inner's scope.
        public static function reachUpProbe(): string {
            return module::Inner::Api::reachUp();
        }
    }
}

// From inside Outer: the internal module and its members are fully usable.
echo "inside: ", Outer::Main::fromOuter(), "\n";

// Non-transitivity: Inner's own code cannot reach Outer's other internal members.
echo "reachUp: ", Outer::Main::reachUpProbe(), "\n";

// From outside Outer: every path into the internal module is denied — even its
// PUBLIC members (const, static function, member class, static-on-member).
$probes = [
    'const'      => fn() => Outer::Inner::IV,
    'static-fn'  => fn() => Outer::Inner::Api::make(),
    'new-member' => fn() => new Outer::Inner::Gadget(),
    'static-on'  => fn() => Outer::Inner::Gadget::who(),
];
foreach ($probes as $label => $probe) {
    try { $probe(); echo "$label: LEAKED\n"; }
    catch (\Error $e) { echo "$label: ", $e->getMessage(), "\n"; }
}

// The internal member class of the internal module is doubly hidden.
try { new Outer::Inner::Hidden(); echo "hidden: LEAKED\n"; }
catch (\Error $e) { echo "hidden: ", $e->getMessage(), "\n"; }

// Reflection still sees the nested module (reflection bypasses visibility).
$r = new ReflectionModule("Outer::Inner");
echo "reflect: ", $r->getName(), "\n";
?>
--EXPECT--
inside: iv/made/gadget/hidden
reachUp: Cannot access internal module member "Outer::OuterSecret" from outside its module
const: Cannot access internal module member "Outer::Inner" from outside its module
static-fn: Cannot access internal module member "Outer::Inner::Api" from outside its module
new-member: Cannot access internal module member "Outer::Inner::Gadget" from outside its module
static-on: Cannot access internal module member "Outer::Inner::Gadget" from outside its module
hidden: Cannot access internal module member "Outer::Inner::Hidden" from outside its module
reflect: Outer::Inner
