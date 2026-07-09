--TEST--
JSON POST: top-level array populates $_POST with numeric keys
--POST_RAW--
Content-Type: application/json
[10, "twenty", {"k":"v"}]
--FILE--
<?php
var_dump($_POST);
?>
--EXPECT--
array(3) {
  [0]=>
  int(10)
  [1]=>
  string(6) "twenty"
  [2]=>
  array(1) {
    ["k"]=>
    string(1) "v"
  }
}
