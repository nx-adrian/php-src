--TEST--
Modules: attributes on a `module { }` block, surfaced via ReflectionModule::getAttributes()
--DESCRIPTION--
A `module { }` definition block may carry attributes with the same syntax as a class.
They attach to the module's backing class with the new ZEND_ATTRIBUTE_TARGET_MODULE
target and are read back via ReflectionModule::getAttributes(), including argument
binding through newInstance() -- full parity with ReflectionClass::getAttributes().
--FILE--
<?php
#[Attribute(Attribute::TARGET_MODULE)]
class Owner {
    public function __construct(public string $team, public int $rev = 1) {}
}

#[Attribute(Attribute::TARGET_MODULE | Attribute::IS_REPEATABLE)]
class Tag {
    public function __construct(public string $v) {}
}

#[Owner("payments", rev: 3)]
#[Tag("a"), Tag("b")]
module Billing {
    public class Ledger {}
}

$r = new ReflectionModule("Billing");

echo "count: ", count($r->getAttributes()), "\n";
echo "tags: ", count($r->getAttributes("Tag")), "\n";

$owner = $r->getAttributes("Owner")[0]->newInstance();
echo "class: ", get_class($owner), "\n";
echo "team: ", $owner->team, "\n";
echo "rev: ", $owner->rev, "\n";

$tags = array_map(fn($a) => $a->newInstance()->v, $r->getAttributes("Tag"));
echo "tagvals: ", implode(",", $tags), "\n";

// A module written without attributes reports an empty array.
module Plain { public class C {} }
echo "plain: ", count((new ReflectionModule("Plain"))->getAttributes()), "\n";
?>
--EXPECT--
count: 3
tags: 2
class: Owner
team: payments
rev: 3
tagvals: a,b
plain: 0
