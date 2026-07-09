--TEST--
JSON POST: enable_post_data_reading=0 disables population of $_POST
--INI--
enable_post_data_reading=0
--POST_RAW--
Content-Type: application/json
{"a":1,"b":"two"}
--FILE--
<?php
var_dump($_POST);
var_dump(file_get_contents("php://input"));
?>
--EXPECT--
array(0) {
}
string(17) "{"a":1,"b":"two"}"
