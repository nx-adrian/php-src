--TEST--
JSON POST: content-type parameters are ignored and php://input keeps the raw body
--POST_RAW--
Content-Type: application/json; charset=UTF-8
{"a":1,"b":"two"}
--FILE--
<?php
var_dump($_POST);
var_dump(file_get_contents("php://input"));
?>
--EXPECT--
array(2) {
  ["a"]=>
  int(1)
  ["b"]=>
  string(3) "two"
}
string(17) "{"a":1,"b":"two"}"
