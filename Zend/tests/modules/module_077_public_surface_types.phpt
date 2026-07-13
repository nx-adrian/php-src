--TEST--
Modules: a public class's public-method return types and public property types may not name the module's own internal/unclaimed types
--DESCRIPTION--
A module's public surface must not expose its own internal (or unclaimed, which is
internal by default) types. Framed positively: a return type or public property type
that names a member of the enclosing module must resolve to a PUBLIC member. Enforced
at compile time, same-module only (another module's members may be cold, so they are
not checked). Parameters are not checked. The escaped-object pattern still works — the
object escapes, its declared type just uses a public supertype (or `object`).
--FILE--
<?php
/* Positive: a public factory typed as a public supertype (or object) still escapes an
 * internal object; its identity stays observable. */
module M {
    public interface I {}
    internal class N implements I { public function tag(): string { return "n"; } }
    public class C {
        public function f1(): I { return new N(); }          // typed as the public interface
        public function esc(): object { return new N(); }    // typed object; still escapes
        internal function g(): N { return new N(); }         // internal method: not public surface
        private function h(): N { return new N(); }          // private: not public surface
    }
    internal class Hidden {
        public function m(): N { return new N(); }           // public method of an INTERNAL class: fine
    }
}
$c = new M::C();
echo get_class($c->f1()), "\n";        // M::N (escaped, typed I)
echo get_class($c->esc()), "\n";       // M::N (escaped, typed object)

/* Compile-time rejections via subprocess. */
function bad(string $label, string $code): void {
    $f = tempnam(sys_get_temp_dir(), 'pst') . '.php';
    file_put_contents($f, "<?php\n" . $code);
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($f) . ' 2>&1');
    @unlink($f);
    echo $label, ": ", str_contains($out, 'not a public member') ? "rejected" : ("UNEXPECTED: " . trim($out)), "\n";
}
function ok(string $label, string $code): void {
    $f = tempnam(sys_get_temp_dir(), 'pst') . '.php';
    file_put_contents($f, "<?php\n" . $code . "\necho 'OK';");
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($f) . ' 2>&1');
    @unlink($f);
    echo $label, ": ", trim($out) === 'OK' ? "ok" : ("UNEXPECTED: " . trim($out)), "\n";
}

echo "--\n";
bad('return internal',   'module M { internal class N {} public class C { public function f(): N { throw new \Exception; } } }');
bad('public property',   'module M { internal class N {} public class C { public N $p; } }');
bad('claimed internal',  'module M { internal Widget; public class C { public function f(): Widget { throw new \Exception; } } }');
bad('bare non-member',   'module M { public class C { public function f(): Whatever { throw new \Exception; } } }');
bad('nullable ?N',       'module M { internal class N {} public class C { public function f(): ?N { return null; } } }');
bad('union I|N',         'module M { public interface I {} internal class N implements I {} public class C { public function f(): I|N { throw new \Exception; } } }');
bad('covariant narrow',  'module M { public interface I {} internal class N implements I {} public class B { public function m(): I { throw new \Exception; } } public class D extends B { public function m(): N { throw new \Exception; } } }');
bad('public trait return', 'module M { internal class N {} public trait T { public function f(): N { throw new \Exception; } } }');

echo "--\n";
ok('public claimed type',   'module M { public Widget; public class C { public function f(): Widget { throw new \Exception; } } }');
ok('object return',         'module M { internal class N {} public class C { public function f(): object { return new N(); } } }');
ok('internal method',       'module M { internal class N {} public class C { internal function g(): N { return new N(); } } }');
ok('cross-module (cold)',   'module M { public class C { public function f(): X::Y { throw new \Exception; } } } module X { internal class Y {} }');
ok('internal trait exempt', 'module M { internal class N {} internal trait T { public function f(): N { return new N(); } } public class U { use T; } }');
ok('global/FQN type',       'module M { public class C { public function f(): \Exception { throw new \Exception; } } }');
?>
--EXPECT--
M::N
M::N
--
return internal: rejected
public property: rejected
claimed internal: rejected
bare non-member: rejected
nullable ?N: rejected
union I|N: rejected
covariant narrow: rejected
public trait return: rejected
--
public claimed type: ok
object return: ok
internal method: ok
cross-module (cold): ok
internal trait exempt: ok
global/FQN type: ok
