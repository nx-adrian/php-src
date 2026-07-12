--TEST--
Modules: "module" and "internal" keywords remain usable as member names (semi-reserved)
--FILE--
<?php
class Registry {
    const module = 'a constant named module';
    public function module(): string { return 'a method named module'; }
    public function internal(): string { return 'a method named internal'; }
}

$r = new Registry();
echo $r->module(), "\n";
echo $r->internal(), "\n";
echo Registry::module, "\n";
?>
--EXPECT--
a method named module
a method named internal
a constant named module
