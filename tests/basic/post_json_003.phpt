--TEST--
JSON POST: invalid JSON leaves $_POST empty but php://input intact
--POST_RAW--
Content-Type: application/json
{"broken":
--FILE--
<?php
var_dump($_POST);
var_dump(file_get_contents("php://input"));
?>
--EXPECT--
array(0) {
}
string(10) "{"broken":"
