--TEST--
Modules: arbitrary-depth "::" in class-reference positions (new/instanceof/extends/type-hints/module::)
--FILE--
<?php
module Outer {
    public module Inner {
        public class Base { public function tag(): string { return "base"; } }
        public class Gadget {
            public function who(): string { return "gadget"; }
            public static function make(): string { return "made"; }
        }
        internal class Secret { public function s(): string { return "secret"; } }

        public module Deep {
            public class Cog { public function spin(): string { return "spin"; } }
        }

        // "module::" self-references, both single- and multi-segment, from inside
        // a member class of the nested module.
        public class Api {
            public static function build(): string {
                return (new module::Gadget)->who()          // module::Gadget
                     . "/" . module::Gadget::make()          // module::Gadget::make()
                     . "/" . (new module::Secret)->s();      // internal, allowed inside
            }
        }
    }
}

// new / instanceof at depth 2 and depth 3
$g = new Outer::Inner::Gadget();
var_dump($g instanceof Outer::Inner::Gadget);
echo $g->who(), "\n";
echo (new Outer::Inner::Deep::Cog())->spin(), "\n";

// extends across a chained module path
class Ext extends Outer::Inner::Base {}
echo (new Ext)->tag(), "\n";

// parameter + return type hints, chained
function useit(Outer::Inner::Base $b): Outer::Inner::Base { return $b; }
echo useit(new Outer::Inner::Base)->tag(), "\n";

// module:: self-references (single and multi-segment)
echo Outer::Inner::Api::build(), "\n";

// internal member is still gated when named through the chain from outside
try {
    new Outer::Inner::Secret();
    echo "LEAKED\n";
} catch (\Error $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
bool(true)
gadget
spin
base
base
gadget/made/secret
Cannot access internal module member "Outer::Inner::Secret" from outside its module
