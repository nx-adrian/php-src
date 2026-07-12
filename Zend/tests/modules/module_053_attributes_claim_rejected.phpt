--TEST--
Modules: attributes are rejected on forward-declared / nested-module members (by design)
--FILE--
<?php
module M {
    #[SomeAttr] public GuestUser;   // a body-less claim has no declaration to carry attributes
}
?>
--EXPECTF--
Fatal error: Attributes are not supported on a forward-declared or nested module member; place them on the member's definition instead in %s on line %d
