--TEST--
Modules: a membership declaration ("module X;") must be the first statement in the file
--DESCRIPTION--
A `module X;` membership declaration is a mode switch that owns the rest of the file, so it
must come first (only a leading declare() may precede it). This keeps a file to a single
form — either brace-delimited blocks, or one membership file — and forbids a definition
block (or code, or a namespace) being followed by a membership "mode switch". The intended
patterns `module X; namespace Y;` and `module X; module Inner { … }` keep the membership
first and remain valid.
--SKIPIF--
<?php if (!function_exists('shell_exec')) die('skip shell_exec disabled'); ?>
--FILE--
<?php
function compiles(string $code): bool {
    $f = tempnam(sys_get_temp_dir(), 'memfirst') . '.php';
    file_put_contents($f, "<?php\n" . $code);
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($f) . ' 2>&1');
    @unlink($f);
    return stripos($out, 'error') === false;
}

echo "-- rejected: membership may not follow a block / code / namespace --\n";
var_dump(compiles('module M { W; } module M; class W {}'));           // block, then membership (same name)
var_dump(compiles('module M { W; } module N; class Q {}'));           // block, then membership (other name)
var_dump(compiles('echo "x"; module M; class Y {}'));                 // code, then membership
var_dump(compiles('namespace A {} module M; class Z {}'));            // namespace block, then membership

echo "-- allowed: membership first, optionally after declare, then namespace / nested block --\n";
var_dump(compiles('module M; class Y {}'));
var_dump(compiles('declare(strict_types=1); module M; class Y {}'));
var_dump(compiles('module M; namespace A; class Y {}'));
var_dump(compiles('module M; module Inner { class G {} }'));
?>
--EXPECT--
-- rejected: membership may not follow a block / code / namespace --
bool(false)
bool(false)
bool(false)
bool(false)
-- allowed: membership first, optionally after declare, then namespace / nested block --
bool(true)
bool(true)
bool(true)
bool(true)
