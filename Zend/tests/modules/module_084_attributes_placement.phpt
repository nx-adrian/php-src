--TEST--
Modules: attributes allowed on a module definition block (top-level or nested), rejected on membership/claim
--DESCRIPTION--
Attributes describe a module's definition, so they are permitted only on a `module { }`
block that actually defines the module -- top-level or nested. They are not permitted on
a body-less membership directive ("module M;") or a nested forward-claim ("module Inner;"),
which merely reference a definition living elsewhere and have no single home for the metadata.
--FILE--
<?php
#[Attribute(Attribute::TARGET_MODULE)]
class Meta { public function __construct(public string $s = "") {} }

// Nested module *definition* block carries attributes, attached to the nested backing
// class. Note: an unqualified name inside a module block is module-relative (like any
// type reference there), so a *global* attribute is named with a leading "\" -- exactly
// as it would be for a property type or return type in the same position.
#[Meta("outer")]
module Outer {
    #[\Meta("inner")]
    public module Inner {
        public class C {}
    }
}

echo "outer: ", (new ReflectionModule("Outer"))->getAttributes("Meta")[0]->newInstance()->s, "\n";
echo "inner: ", (new ReflectionModule("Outer::Inner"))->getAttributes("Meta")[0]->newInstance()->s, "\n";

function reject(string $label, string $code): void {
    $f = tempnam(sys_get_temp_dir(), 'mattr') . '.php';
    file_put_contents($f, "<?php\n" . $code);
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($f) . ' 2>&1');
    @unlink($f);
    $bad = str_contains($out, 'not supported') || str_contains($out, 'syntax error');
    echo $label, ": ", $bad ? "rejected" : ("UNEXPECTED: " . trim($out)), "\n";
}

echo "-- placement --\n";
reject('top-level membership', '#[Meta] module M;');
reject('nested claim', 'module Outer2 { #[Meta] public module Inner2; }');
?>
--EXPECT--
outer: outer
inner: inner
-- placement --
top-level membership: rejected
nested claim: rejected
