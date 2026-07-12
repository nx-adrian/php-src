--TEST--
Modules: "module::X" requires X to be a declared member of the current module
--FILE--
<?php
module M {
    public class A {}
    public class B {
        // "Nope" is not declared by module M -> resolution must fail, proving that
        // module:: resolves by membership, not merely by a matching canonical name.
        public function f(): module::Nope {}
    }
}
?>
--EXPECTF--
Fatal error: "Nope" is not a member of module "M" in %s on line %d
