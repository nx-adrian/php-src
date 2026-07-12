--TEST--
Modules: nested modules are not supported yet (parse error)
--FILE--
<?php
module A { module B { public class C {} } }
?>
--EXPECTF--
Parse error: syntax error, unexpected token "module"%s in %s on line %d
