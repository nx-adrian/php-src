--TEST--
Modules: module static properties on the backing class (M::$x / module::$x)
--FILE--
<?php
module Counter {
    public static int $count = 0;
    public static string $label = "n";

    public static function bump(): void { module::$count++; }        // module::$x write
    public static function tag(): string { return module::$label; }  // module::$x read
}

Counter::bump();
Counter::bump();
Counter::bump();
echo Counter::$count, "\n";           // external read -> 3

Counter::$count = 100;                  // external write
echo Counter::$count, "\n";           // -> 100

Counter::$label = "hits";
echo Counter::tag(), "\n";            // module::$label read from inside -> hits
?>
--EXPECT--
3
100
hits
