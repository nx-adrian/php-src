--TEST--
$_POST is populated from an application/json request body
--POST_RAW--
Content-Type: application/json
{"name":"Adrian","age":30,"active":true,"score":1.5,"nothing":null,"tags":["a","b"],"nested":{"x":1}}
--FILE--
<?php
var_dump($_POST);
?>
--EXPECT--
array(7) {
  ["name"]=>
  string(6) "Adrian"
  ["age"]=>
  int(30)
  ["active"]=>
  bool(true)
  ["score"]=>
  float(1.5)
  ["nothing"]=>
  NULL
  ["tags"]=>
  array(2) {
    [0]=>
    string(1) "a"
    [1]=>
    string(1) "b"
  }
  ["nested"]=>
  array(1) {
    ["x"]=>
    int(1)
  }
}
