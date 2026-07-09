--TEST--
JSON POST: scalar top-level value leaves $_POST empty but php://input intact
--POST_RAW--
Content-Type: application/json
"just a string"
--FILE--
<?php
var_dump($_POST);
var_dump(file_get_contents("php://input"));
?>
--EXPECT--
array(0) {
}
string(15) ""just a string""
