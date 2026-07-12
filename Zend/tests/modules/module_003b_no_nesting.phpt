--TEST--
Modules: a nested module declaration requires an explicit visibility modifier
--DESCRIPTION--
Nested modules are supported (see module_033/035/041), but — like every other member
of a module block — a nested module must declare its visibility. A bare "module B"
with no public/internal modifier inside a module block is a parse error. The positive
forms ("public module B { ... }" / "internal module B { ... }") are exercised elsewhere.
--FILE--
<?php
module A { module B { public class C {} } }
?>
--EXPECTF--
Parse error: syntax error, unexpected token "module", expecting "internal" or "public" or "#[" or "}" in %s on line %d
