--TEST--
Modules: a body-less claim name must be a declarable name (no semi_reserved keywords)
--DESCRIPTION--
A claim forward-declares a split-file member by name. Its name may only be a plain
identifier or qualified name — the same space a real class/interface/trait/enum
declaration can occupy — never a `semi_reserved` keyword such as "public", "list", or
"readonly". Such a symbol could never be defined (`class public {}` is itself illegal),
so claiming an un-declarable name is a parse error rather than a claim that can never
bind. Legitimate claims (plain and qualified names) are exercised in module_007/012/etc.
--FILE--
<?php
module M { internal public; }
?>
--EXPECTF--
Parse error: syntax error, unexpected token "public" in %s on line %d
