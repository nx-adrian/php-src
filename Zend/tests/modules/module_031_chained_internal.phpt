--TEST--
Modules: chained "::" respects internal (member class and a member's internal method)
--FILE--
<?php
module M {
    internal class Secret {
        public static function s(): string { return "x"; }
    }
    public class Pub {
        internal static function priv(): string { return "p"; }
        public static function ok(): string { return "ok"; }
    }
}

// Public member class, public static method via chain: allowed
echo M::Pub::ok(), "\n";

// Internal member class reached via a chain: denied at the class fetch
echo "internal class: ";
try { M::Secret::s(); echo "LEAK\n"; } catch (\Error $e) { echo "blocked\n"; }

// Internal static method of a public member class, via chain: denied at dispatch
echo "internal method: ";
try { M::Pub::priv(); echo "LEAK\n"; } catch (\Error $e) { echo "blocked\n"; }
?>
--EXPECT--
ok
internal class: blocked
internal method: blocked
