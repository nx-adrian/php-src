--TEST--
Modules: an `internal` trait is usable only within its own module, and a trait that declares any `internal` member must itself be `internal`
--DESCRIPTION--
Trait members are flattened into the using class, so a module member's internal-ness
is enforced relative to the class the trait dissolves into. An `internal` trait may
therefore be used only by a class in its own module (checked at the binding site
against the using class, like extends); its public members are usable through that
class, its internal members stay gated. Because a *public* trait can be used by
classes outside the module — where its internal members would leak or be transplanted
into another module — a trait that declares any `internal` member must be declared
`internal` (a compile error otherwise).
--FILE--
<?php
/* Positive runtime behavior: an internal trait used within its own module. */
module M {
    internal trait T {
        public function pub(): string { return "pub"; }
        internal function sec(): string { return "sec"; }
    }
    public class InUser {
        use T;
        public function callSec(): string { return $this->sec(); }  // inside module: allowed
    }
}
$o = new M::InUser();
echo $o->pub(), "\n";           // public trait method, usable from outside via the public class
echo $o->callSec(), "\n";       // internal trait method, reached from inside the module
try { $o->sec(); } catch (\Error $e) { echo $e->getMessage(), "\n"; }  // internal: denied outside

/* Compile-time / boundary cases via subprocess (each aborts its own process). */
function run(string $code): string {
    $f = tempnam(sys_get_temp_dir(), 'itr') . '.php';
    file_put_contents($f, "<?php\n" . $code);
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($f) . ' 2>&1');
    @unlink($f);
    return trim($out);
}

echo "--\n";
// internal trait used by a non-module (global) class -> denied
echo run('module M { internal trait T { public function p(){ return "p"; } } } class Outside { use M::T; } echo "LEAK";'), "\n";
// internal trait used by a class in ANOTHER module -> denied
echo run('module M { internal trait T { public function p(){ return "p"; } } } module N { public class U { use \M::T; } } echo "LEAK";'), "\n";
// Rule #1: public trait with an internal method -> compile error
echo run('module M { public trait T { public function a(){} internal function b(){} } } echo "LEAK";'), "\n";
// Rule #1: public trait with an internal property -> compile error
echo run('module M { public trait T { public int $x = 1; internal int $y = 2; } } echo "LEAK";'), "\n";
// Rule #1: bare trait (defaults public) with an internal method -> compile error
echo run('module M { trait T { internal function b(){} } } echo "LEAK";'), "\n";

echo "--\n";
// A public trait with only public/private members is fine, and usable from outside.
echo run('module M { public trait T { public function a(){ return "a"; } private function h(){ return "h"; } } }'
       . ' class Outside { use M::T; } echo (new Outside)->a();'), "\n";
?>
--EXPECTF--
pub
sec
Cannot call internal method M::InUser::sec() from outside its module
--
%aCannot access internal module member "M::T" from outside its module%a
%aCannot access internal module member "M::T" from outside its module%a
%aTrait M::T declares an internal method M::T::b() but is not itself internal; a trait with internal members must be declared `internal` so it can only be used within its own module%a
%aTrait M::T declares an internal property M::T::$y but is not itself internal; a trait with internal members must be declared `internal` so it can only be used within its own module%a
%aTrait M::T declares an internal method M::T::b() but is not itself internal; a trait with internal members must be declared `internal` so it can only be used within its own module%a
--
a
