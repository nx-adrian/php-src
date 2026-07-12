--TEST--
Modules: abstract/trait/interface composition extended from outside; internal enforced only when reached
--FILE--
<?php
module Core {
    public interface Plugin { public function name(): string; }
    public trait Tagged { public function tag(): string { return "tag:" . static::class; } }
    public abstract class Base implements Plugin {
        use Tagged;
        public function __construct(public readonly int $id) {}
        abstract public function name(): string;
    }
    internal class Secret {}
}

// Extend a public abstract module class from outside and implement its abstract method;
// the module trait and interface come along.
class Widget extends Core::Base {
    public function name(): string { return "widget"; }
}
$w = new Widget(7);
echo $w->name(), " ", $w->id, "\n";
var_dump($w instanceof Core::Plugin, str_contains($w->tag(), "Widget"));

// Internal enforcement is runtime and only fires when the access actually executes:
// a reference to an internal member in an unreached branch raises nothing.
function maybe(bool $run): string {
    if ($run) {
        $x = new Core::Secret();   // never reached when $run is false
        return "ran";
    }
    return "skipped";
}
echo maybe(false), "\n";           // no error — the internal reference is not reached

try {
    maybe(true);                   // now it executes -> denied
    echo "LEAK\n";
} catch (\Error $e) {
    echo "denied when reached\n";
}
?>
--EXPECT--
widget 7
bool(true)
bool(true)
skipped
denied when reached
