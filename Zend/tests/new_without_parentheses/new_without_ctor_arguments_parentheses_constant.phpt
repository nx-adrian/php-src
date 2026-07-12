--TEST--
Immediate constant access on new object without constructor parentheses
--DESCRIPTION--
With the PHP Modules feature, "::" is a module boundary, so "Name::bareword" is
valid syntax in a class-reference position (it denotes a module member). "new A::C"
therefore parses as a reference to a class named "A::C"; A is an ordinary class (not
a module) and no such class exists, so this is a runtime "class not found" Error
rather than the former parse error. To instantiate a class whose name is stored in a
constant, parenthesize: "new (A::C)".
--FILE--
<?php

class A
{
    const C = 'constant';
}

echo new A::C;

?>
--EXPECTF--
Fatal error: Uncaught Error: Class "A::C" not found in %s:%d
Stack trace:
#0 {main}
  thrown in %s on line %d
