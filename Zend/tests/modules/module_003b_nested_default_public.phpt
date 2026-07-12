--TEST--
Modules: a nested module with no visibility keyword defaults to public
--DESCRIPTION--
Visibility is optional on the members of a module definition block and defaults to public;
the rule is keyed on the block (unmarked inside `module { }` is public, `internal` is
explicit). A bare `module B { ... }` is therefore a public nested module, and a bare member
inside it is public too. This exact source was historically a parse error, back when a
visibility modifier was mandatory on every member.
--FILE--
<?php
module A { module B { class C {} } }

$c = new A::B::C();
var_dump($c instanceof A::B::C);
echo (new ReflectionModule("A"))->getSymbolVisibility("A::B"), "\n";
?>
--EXPECT--
bool(true)
public
