--TEST--
JSON POST: numeric-string object keys are normalized to integer keys
--POST_RAW--
Content-Type: application/json
{"0":"zero","10":"ten","x":"y"}
--FILE--
<?php
var_dump($_POST);
var_dump(array_key_exists(0, $_POST), array_key_exists(10, $_POST));
?>
--EXPECT--
array(3) {
  [0]=>
  string(4) "zero"
  [10]=>
  string(3) "ten"
  ["x"]=>
  string(1) "y"
}
bool(true)
bool(true)
