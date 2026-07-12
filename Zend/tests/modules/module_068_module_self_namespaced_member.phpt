--TEST--
Modules: "module::" self-reference reaches a NAMESPACED member (module::Ns\Member from inside)
--FILE--
<?php
$dir = __DIR__ . '/msn_tmp';
@mkdir($dir);
@mkdir("$dir/M");
@mkdir("$dir/M/Ns");

// Module M with an inline member (Facade) that reaches a namespaced split-file member
// (Ns\Thing) through the "module::" self-reference from inside the boundary.
file_put_contents("$dir/M.php", "<?php\nmodule M {\n    public Ns\\Thing;\n    public class Facade {\n        public static function tag(): string { return module::Ns\\Thing::TAG; }\n        public static function make(): object { return new module::Ns\\Thing(); }\n    }\n}\n");
file_put_contents("$dir/M/Ns/Thing.php", "<?php\nmodule M;\nnamespace Ns;\nclass Thing { const TAG = 'thing'; public function who(): string { return 'thing-obj'; } }\n");

spl_autoload_register(function ($name) use ($dir) {
    $f = "$dir/" . str_replace('\\', '/', $name) . '.php';
    if (is_file($f)) require $f;
});

echo M::Facade::tag(), "\n";              // module::Ns\Thing::TAG  (self-ref const)
echo M::Facade::make()->who(), "\n";      // new module::Ns\Thing   (self-ref new)
echo M::Ns\Thing::TAG, "\n";              // external Module::Ns\Member for comparison
echo M::Ns\Thing::class, "\n";            // ::class on the namespaced member
?>
--CLEAN--
<?php
$dir = __DIR__ . '/msn_tmp';
@array_map('unlink', glob("$dir/M/Ns/*"));
@array_map('unlink', glob("$dir/*.php"));
@rmdir("$dir/M/Ns"); @rmdir("$dir/M"); @rmdir($dir);
?>
--EXPECT--
thing
thing-obj
thing
M::Ns\Thing
