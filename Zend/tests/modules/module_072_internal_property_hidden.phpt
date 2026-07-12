--TEST--
Modules: an internal property is hidden from json_encode / (array) cast outside its module
--FILE--
<?php
module M {
    public class C {
        public int $pub = 1;
        internal string $secret = "s3cr3t";
        public function inside(): array {
            // From inside the module, the internal property is visible everywhere.
            return ['gov' => get_object_vars($this), 'cast' => (array)$this, 'json' => json_encode($this)];
        }
    }
}
$c = new M::C();

echo "-- from OUTSIDE the module: internal hidden like private --\n";
echo "get_object_vars: ", json_encode(get_object_vars($c)), "\n";   // already hidden
echo "(array) cast:    ", json_encode((array)$c), "\n";             // hidden (was leaking)
echo "json_encode:     ", json_encode($c), "\n";                    // hidden (was leaking)
echo "foreach:         "; foreach ($c as $k => $v) echo "$k "; echo "\n";

echo "-- from INSIDE the module: internal visible --\n";
$in = $c->inside();
echo "get_object_vars: ", json_encode($in['gov']), "\n";
echo "(array) cast:    ", json_encode($in['cast']), "\n";
echo "json_encode:     ", $in['json'], "\n";

echo "-- a dynamic property does not un-hide the internal one (slow path) --\n";
$c2 = new M::C();
@($c2->dyn = 9);
echo "(array) cast:    ", json_encode((array)$c2), "\n";
echo "json_encode:     ", json_encode($c2), "\n";
?>
--EXPECT--
-- from OUTSIDE the module: internal hidden like private --
get_object_vars: {"pub":1}
(array) cast:    {"pub":1}
json_encode:     {"pub":1}
foreach:         pub 
-- from INSIDE the module: internal visible --
get_object_vars: {"pub":1,"secret":"s3cr3t"}
(array) cast:    {"pub":1,"secret":"s3cr3t"}
json_encode:     {"pub":1,"secret":"s3cr3t"}
-- a dynamic property does not un-hide the internal one (slow path) --
(array) cast:    {"pub":1,"dyn":9}
json_encode:     {"pub":1,"dyn":9}
