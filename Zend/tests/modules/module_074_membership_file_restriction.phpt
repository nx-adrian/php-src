--TEST--
Modules: a module membership file ("module M;") may only contain class-like declarations; non-class-like statements (const, function, variables, imperative code) are a compile error (they would leak to global scope)
--DESCRIPTION--
The module boundary only scopes class-like declarations to the module. A top-level
const, function, variable, or any imperative statement in a membership file is not
module-scoped and would silently land in the global scope. Such a file is rejected
at compile time. Class-like declarations, use imports, declare(), and nested
module/namespace blocks remain allowed.
--FILE--
<?php
function lint(string $code): string {
    $f = tempnam(sys_get_temp_dir(), 'mbr') . '.php';
    file_put_contents($f, "<?php\n" . $code);
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -n -l ' . escapeshellarg($f) . ' 2>&1');
    @unlink($f);
    return $out;
}
function reject(string $label, string $code, string $needle): void {
    $out = lint($code);
    echo str_contains($out, $needle) ? "reject ok: $label\n" : "REJECT FAIL: $label -> $out\n";
}
function allow(string $label, string $code): void {
    $out = lint($code);
    echo str_contains($out, 'No syntax errors') ? "allow ok:  $label\n" : "ALLOW FAIL: $label -> $out\n";
}

// --- rejected: non-class-like content in a membership file ---
reject('const',       "module M;\nconst K = 1;\n",              'const declaration is not allowed');
reject('function',    "module M;\nfunction f() {}\n",           'function declaration is not allowed');
reject('variable',    "module M;\n\$x = 5;\n",                  'the global scope rather than the module');
reject('echo',        "module M;\necho \"hi\";\n",              'the global scope rather than the module');
reject('if-block',    "module M;\nif (true) { class C {} }\n",  'the global scope rather than the module');

// --- allowed: class-like declarations, use, declare, nested module, namespace ---
allow('class',        "module M;\nclass C {}\n");
allow('interface',    "module M;\ninterface I {}\n");
allow('enum',         "module M;\nenum E { case A; }\n");
allow('trait',        "module M;\ntrait T {}\n");
allow('declare+use',  "declare(strict_types=1);\nmodule M;\nuse Other::Thing;\nclass C {}\n");
allow('nested module',"module Outer;\nmodule Inner { public class G {} }\nclass Top {}\n");
allow('namespace',    "module M;\nnamespace Bar { class D {} }\n");
?>
--EXPECT--
reject ok: const
reject ok: function
reject ok: variable
reject ok: echo
reject ok: if-block
allow ok:  class
allow ok:  interface
allow ok:  enum
allow ok:  trait
allow ok:  declare+use
allow ok:  nested module
allow ok:  namespace
