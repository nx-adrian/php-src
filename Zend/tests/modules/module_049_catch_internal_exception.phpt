--TEST--
Modules: an internal exception type can be caught from outside (identity is observable)
--DESCRIPTION--
The module boundary gates *use* of an internal type (new / extends / implements / trait
use), not identity/observation. Identity positions — instanceof, is_a, type declarations,
and catch — see through, exactly as they do for private. catch in particular must work,
or a module could throw an exception type no external caller can name and catch.
--FILE--
<?php
module Bank {
    internal class Overdraft extends \RuntimeException {}
    public class Api {
        public static function withdraw(int $cents): void {
            if ($cents > 100) { throw new module::Overdraft("insufficient funds"); }
        }
    }
}

// Identity/observation of the internal type from outside is allowed:
try {
    Bank::Api::withdraw(500);
    echo "no throw\n";
} catch (Bank::Overdraft $e) {                 // catch by the internal name -> works
    echo "caught: ", $e->getMessage(), "\n";
    var_dump($e instanceof Bank::Overdraft);   // instanceof by internal name -> true
    var_dump(is_a($e, 'Bank::Overdraft'));     // is_a by internal name -> true
}

// A union catch mixing the internal type with a global one still works:
try {
    Bank::Api::withdraw(500);
} catch (\LogicException | Bank::Overdraft $e) {
    echo "union caught: ", get_class($e), "\n";
}

// But *use* of the internal type from outside is still denied:
try { new Bank::Overdraft("x"); echo "LEAKED new\n"; }
catch (\Error $e) { echo $e->getMessage(), "\n"; }
?>
--EXPECT--
caught: insufficient funds
bool(true)
bool(true)
union caught: Bank::Overdraft
Cannot access internal module member "Bank::Overdraft" from outside its module
