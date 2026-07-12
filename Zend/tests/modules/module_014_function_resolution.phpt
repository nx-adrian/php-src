--TEST--
Modules: function/constant name resolution keeps global fallback inside a module
--FILE--
<?php
module Vendor\App {
    public class Service {
        public function bare(): string {
            // Bare global function calls must fall back to the root namespace,
            // NOT be module-prefixed (which would look for Vendor\App::strtoupper).
            return strtoupper(trim("  ok  ")) . strlen("abcd");
        }
        public function rooted(): string {
            return \strtoupper("x") . \strlen("yz");
        }
        public function globalConst(): float {
            return sqrt(M_PI > 3 ? 16.0 : 0.0);   // global function + global constant
        }
    }
}

$s = new Vendor\App::Service();
echo $s->bare(), "\n";
echo $s->rooted(), "\n";
echo $s->globalConst(), "\n";

// A bare class-like reference, by contrast, DOES resolve module-relative.
module Widgets {
    public interface Drawable {}
    public class Button implements Drawable {}   // bare "Drawable" -> Widgets::Drawable
}
var_dump((new Widgets::Button) instanceof Widgets::Drawable);
?>
--EXPECT--
OK4
X2
4
bool(true)
