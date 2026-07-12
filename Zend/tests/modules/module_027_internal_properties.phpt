--TEST--
Modules: internal properties enforce (static on a member class, instance on member classes)
--FILE--
<?php
module Bank {
    // internal static property on a member class
    public class Ledger {
        internal static int $balance = 0;
        public static function credit(int $n): void { self::$balance += $n; }
        public static function balance(): int { return self::$balance; }
    }

    public class Account {
        // internal instance property on a member class
        internal string $pin = "0000";
        public function check(string $p): bool { return $this->pin === $p; }  // inside: read ok
        public function rotate(string $p): void { $this->pin = $p; }            // inside: write ok
    }
}

// Static internal property: usable via the class's own API, denied directly from outside
Bank::Ledger::credit(50);
Bank::Ledger::credit(25);
echo Bank::Ledger::balance(), "\n";                       // 75
echo "outside static: ";
try { echo Bank::Ledger::$balance; } catch (\Error $e) { echo $e->getMessage(), "\n"; }

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
outside static: Cannot access internal module property Bank::Ledger::$balance from outside its module
bool(true)
outside instance read: Cannot access internal module property Bank::Account::$pin from outside its module
outside instance write: blocked
