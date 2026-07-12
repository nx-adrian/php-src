--TEST--
Modules: "module" and "internal" are semi-reserved — usable as member names, like public/private
--DESCRIPTION--
"internal" is a module visibility modifier, but — exactly like the other visibility words
(public/private/protected/readonly) — it is only *semi*-reserved: it may still be used as a
method, constant, or property name. "module" is likewise semi-reserved. Neither is fully
reserved, so ordinary member-name uses keep compiling; both still function in their keyword
roles (module declarations / the internal visibility modifier).
--FILE--
<?php
class Registry {
    const module = 'const module';
    const internal = 'const internal';
    public function module(): string { return 'method module'; }
    public function internal(): string { return 'method internal'; }
    public $internal = 'prop internal';
}
$r = new Registry();
echo $r->module(), " / ", $r->internal(), "\n";
echo Registry::module, " / ", Registry::internal, "\n";
echo $r->internal, "\n";

// "internal" still works as a module visibility modifier (its keyword role):
module M {
    internal class Hidden {}
    public class Shown {}
}
echo (new M::Shown()) instanceof M::Shown ? "Shown ok\n" : "FAIL\n";
try { new M::Hidden(); echo "LEAK\n"; }
catch (\Error $e) { echo "Hidden gated\n"; }
?>
--EXPECT--
method module / method internal
const module / const internal
prop internal
Shown ok
Hidden gated
