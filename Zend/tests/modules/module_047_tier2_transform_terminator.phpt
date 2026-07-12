--TEST--
Modules: tier-2 autoload transform ("::"->"\") null-terminates the shortened name
--DESCRIPTION--
The tier-2 transform compacts "Module::Member" to "Module\Member" in place (one byte
shorter per boundary). zend_string_truncate's fast path updates the length but does not
write the terminator, so the shortened name must be null-terminated explicitly. If it is
not, the byte at the new length keeps a stale character and freeing that string trips the
"String is not null-terminated" assertion on a debug build. Capturing the name the engine
hands the autoloader keeps it alive until shutdown, where the mis-terminated free occurs.
--FILE--
<?php
$dir = __DIR__ . '/al47_tmp';
@mkdir($dir);
file_put_contents("$dir/Depot.def.php", "<?php\nmodule Depot {\n    public Crate;\n}\n");
file_put_contents("$dir/Depot.Crate.php", "<?php\nmodule Depot;\nclass Crate { public function id(): string { return 'crate'; } }\n");

$GLOBALS['seen'] = [];
spl_autoload_register(function ($name) use ($dir) {
    $GLOBALS['seen'][] = $name;               // keep the transformed name alive past this call
    $map = [
        'Depot'        => "$dir/Depot.def.php",
        'Depot\\Crate' => "$dir/Depot.Crate.php",
    ];
    if (isset($map[$name]) && is_file($map[$name])) require $map[$name];
});

echo (new Depot::Crate)->id(), "\n";
foreach ($GLOBALS['seen'] as $n) {
    echo $n, " (", strlen($n), ")\n";
}
?>
--CLEAN--
<?php
$dir = __DIR__ . '/al47_tmp';
@array_map('unlink', glob("$dir/*"));
@rmdir($dir);
?>
--EXPECT--
crate
Depot (5)
Depot\Crate (11)
