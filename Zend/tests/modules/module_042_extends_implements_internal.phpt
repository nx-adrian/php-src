--TEST--
Modules: extends/implements a module type across the boundary (internal denied incl. early-bound; public/trait ok)
--FILE--
<?php
module Vendor {
    public class PublicBase { public function b(): string { return "base"; } }
    internal class InternalBase {}
    public interface Payable {}
    public trait Helper { public function h(): string { return "help"; } }

    // Inside the module, extending an internal base is allowed.
    public class Derived extends module::InternalBase {}
}

// Public base/interface/trait are usable from outside (all newly parse via "::").
class Ext extends \Vendor::PublicBase {}
echo (new Ext)->b(), "\n";
class Impl implements \Vendor::Payable {}
echo (new Impl instanceof \Vendor::Payable ? "impl-ok" : "no"), "\n";
class User { use \Vendor::Helper; }
echo (new User)->h(), "\n";
echo (new Vendor::Derived instanceof Vendor::InternalBase ? "inside-ok" : "no"), "\n";

// Crossing the boundary from outside is denied — including early binding (a top-level
// class whose parent is already declared), which previously slipped through. Run in
// subprocesses because the (consumer-side) violation is a fatal, like `extends final`.
$php = getenv('TEST_PHP_EXECUTABLE');
function deny(string $php, string $code): string {
    $out = shell_exec($php . ' -n -r ' . escapeshellarg($code) . ' 2>&1');
    return strpos($out, 'internal module member') !== false ? 'denied' : trim($out);
}
$pre = 'module Vendor { internal class IB {} internal interface II {} } ';
echo "ext internal:  ", deny($php, $pre . 'class B extends \Vendor::IB {}'), "\n";
echo "impl internal: ", deny($php, $pre . 'class C implements \Vendor::II {}'), "\n";
?>
--EXPECT--
base
impl-ok
help
inside-ok
ext internal:  denied
impl internal: denied
