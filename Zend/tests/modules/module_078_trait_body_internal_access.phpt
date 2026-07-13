--TEST--
Modules: a public trait's body may only access provably-public module members; accessing the module's own internal member classes is a compile error
--DESCRIPTION--
A public trait is flattened into classes outside its module, and `module::`/bare
references in its body bind to the trait's DEFINING module. An access to an internal
member would therefore fail at runtime when the trait is used outside that module. So
a public trait's body may reference only provably-public module members; an internal
member class (via new / static access / instanceof / a chained ref) is a compile
error. Public members are allowed. An internal trait is exempt (used only within its
module), and an ordinary class is unaffected. (Residual: an internal module *constant*
accessed via `module::CONST` is not caught here — constant visibility is enforced at
runtime — and still fails when the trait is used outside the module.)
--FILE--
<?php
/* Positive: a public trait referencing only PUBLIC module members works when flattened
 * into a non-module class outside the module. */
module M {
    public class Pub { public static function hi(): string { return "hi"; } }
    public const VERSION = "1.0";
    public trait T {
        public function greet(): string { return module::Pub::hi() . "/" . module::VERSION; }
    }
}
class Outside { use M::T; }
echo (new Outside)->greet(), "\n";   // reaches M's public members from outside — fine

function bad(string $label, string $code): void {
    $f = tempnam(sys_get_temp_dir(), 'tba') . '.php';
    file_put_contents($f, "<?php\n" . $code);
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($f) . ' 2>&1');
    @unlink($f);
    echo $label, ": ", str_contains($out, 'may not access the internal module member') ? "rejected" : ("UNEXPECTED: " . trim($out)), "\n";
}
function ok(string $label, string $code): void {
    $f = tempnam(sys_get_temp_dir(), 'tba') . '.php';
    file_put_contents($f, "<?php\n" . $code . "\necho 'OK';");
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($f) . ' 2>&1');
    @unlink($f);
    echo $label, ": ", trim($out) === 'OK' ? "ok" : ("UNEXPECTED: " . trim($out)), "\n";
}

echo "--\n";
bad('new internal',       'module M { internal class N {} public trait T { public function f() { return new N(); } } }');
bad('module::N::m()',     'module M { internal class N { public static function m(){} } public trait T { public function f() { return module::N::m(); } } }');
bad('instanceof internal','module M { internal class N {} public trait T { public function f($x): bool { return $x instanceof N; } } }');
bad('N::CONST internal',  'module M { internal class N { public const K = 1; } public trait T { public function f() { return N::K; } } }');

echo "--\n";
ok('public member access', 'module M { public class P { public static function hi(){ return "h"; } } public trait T { public function f(): string { return module::P::hi(); } } } class X { use M::T; }');
ok('public claimed access','module M { public Widget; public trait T { public function f() { return new Widget(); } } }');
ok('internal trait exempt','module M { internal class N {} internal trait T { public function f() { return new N(); } } public class U { use T; } }');
ok('ordinary class ok',    'module M { internal class N {} public class C { public function f() { return new N(); } } }');
ok('global/FQN ref ok',    'module M { public trait T { public function f() { throw new \Exception; } } }');
?>
--EXPECT--
hi/1.0
--
new internal: rejected
module::N::m(): rejected
instanceof internal: rejected
N::CONST internal: rejected
--
public member access: ok
public claimed access: ok
internal trait exempt: ok
ordinary class ok: ok
global/FQN ref ok: ok
