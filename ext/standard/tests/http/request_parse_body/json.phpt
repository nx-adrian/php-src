--TEST--
request_parse_body() with application/json
--ENV--
REQUEST_METHOD=PUT
--POST_RAW--
Content-Type: application/json
{"foo":"foo","bar":[1,2]}
--FILE--
<?php

[$_POST, $_FILES] = request_parse_body();

var_dump($_POST, $_FILES);

?>
--EXPECT--
array(2) {
  ["foo"]=>
  string(3) "foo"
  ["bar"]=>
  array(2) {
    [0]=>
    int(1)
    [1]=>
    int(2)
  }
}
array(0) {
}
