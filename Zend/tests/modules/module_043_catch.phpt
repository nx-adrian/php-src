--TEST--
Modules: catch a module exception type by its "::" name (single, chained, union)
--FILE--
<?php
module Outer {
    public class AppError extends \Exception {}
    public module Inner {
        public class IoError extends \Exception {}
    }
}

// Single "::" catch type.
try { throw new Outer::AppError("boom"); }
catch (\Outer::AppError $e) { echo "single: ", $e->getMessage(), "\n"; }

// Chained "::" catch type.
try { throw new Outer::Inner::IoError("io"); }
catch (\Outer::Inner::IoError $e) { echo "chained: ", $e->getMessage(), "\n"; }

// Union of module catch types.
foreach ([new Outer::AppError("a"), new Outer::Inner::IoError("b")] as $ex) {
    try { throw $ex; }
    catch (\Outer::AppError | \Outer::Inner::IoError $e) { echo "union: ", get_class($e), "=", $e->getMessage(), "\n"; }
}
?>
--EXPECT--
single: boom
chained: io
union: Outer::AppError=a
union: Outer::Inner::IoError=b
