--TEST--
Modules: internal properties enforce (static on the backing class, instance on member classes)
--FILE--
<?php
module Bank {
    // internal module-level static property (on the backing class)
    internal static int $ledger = 0;

    public static function credit(int $n): void { module::$ledger += $n; }
    public static function balance(): int { return module::$ledger; }

    public class Account {
        // internal instance property on a member class
        internal string $pin = "0000";
        public function check(string $p): bool { return $this->pin === $p; }  // inside: read ok
        public function rotate(string $p): void { $this->pin = $p; }            // inside: write ok
    }
}

// Static internal property: usable via the module's own API, denied directly from outside
Bank::credit(50);
Bank::credit(25);
echo Bank::balance(), "\n";                       // 75
echo "outside static: ";
try { echo Bank::$ledger; } catch (\Error $e) { echo $e->getMessage(), "\n"; }

// Instance internal property: usable inside the module, denied from outside
$a = new Bank::Account();
$a->rotate("1234");
var_dump($a->check("1234"));                        // true (inside)
echo "outside instance read: ";
try { echo $a->pin; } catch (\Error $e) { echo $e->getMessage(), "\n"; }
echo "outside instance write: ";
try { $a->pin = "9999"; echo "ALLOWED\n"; } catch (\Error $e) { echo "blocked\n"; }
?>
--EXPECT--
75
outside static: Cannot access internal module property Bank::$ledger from outside its module
bool(true)
outside instance read: Cannot access internal module property Bank::Account::$pin from outside its module
outside instance write: blocked
