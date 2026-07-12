--TEST--
Immediate static method call on new object without constructor parentheses
--DESCRIPTION--
With the PHP Modules feature, "::" is a module boundary, so "Name::bareword" is
valid syntax in a class-reference position. "new A::test()" parses as a reference to
a class named "A::test" followed by constructor arguments; A is an ordinary class
(not a module) and no such class exists, so this is a runtime "class not found"
Error rather than the former parse error.
--FILE--
<?php

class A
{
    public static function test(): void
    {
        echo 'called';
    }
}

new A::test();

?>
--EXPECTF--
Fatal error: Uncaught Error: Class "A::test" not found in %s:%d
Stack trace:
#0 {main}
  thrown in %s on line %d
