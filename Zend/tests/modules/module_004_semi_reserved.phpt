--TEST--
Modules: "module" remains usable as a member name (semi-reserved); "internal" is a reserved modifier
--FILE--
<?php
// "module" stays semi-reserved: usable as a method/constant/property name.
class Registry {
    const module = 'a constant named module';
    public function module(): string { return 'a method named module'; }
}

$r = new Registry();
echo $r->module(), "\n";
echo Registry::module, "\n";

// "internal" is a reserved visibility modifier (like public/private), so it is
// NOT usable as a bare identifier. Confirm that is a parse error.
try {
    eval('class K { public function internal() {} }');
    echo "internal-as-method: no error\n";
} catch (\ParseError $e) {
    echo "internal-as-method: ParseError\n";
}
?>
--EXPECT--
a method named module
a constant named module
internal-as-method: ParseError
