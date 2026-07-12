--TEST--
Modules: attributes on inline module members (every kind), with module-aware name resolution
--FILE--
<?php
#[\Attribute(\Attribute::TARGET_ALL)]
class Tag { public function __construct(public string $v) {} }

interface Base {}

module App {
    // A module-local attribute, referenced by a bare (module-relative) name below.
    #[\Attribute(\Attribute::TARGET_ALL)]
    public class Local { public function __construct(public string $v) {} }

    #[\Tag("cls")]                    public class C implements \Base {}
    #[\Tag("iface")]                  public interface Contract {}
    #[\Tag("trait")]                  public trait Helper {}
    #[\Tag("enum")]                   public enum E: string { case X = "x"; }
    #[\Tag("fn")]                     public static function f(): void {}
    #[\Tag("prop")]                   public static string $p = "v";
    #[\Tag("const")]                  public const K = 1;
    #[Local("bare")]                  public class UsesLocal {}   // bare -> App::Local
    #[\Tag("intern")]                 internal class Secret {}    // attribute + internal compose
}

function tagOf(array $attrs): string {
    return $attrs === [] ? "NONE" : $attrs[0]->getName() . ":" . $attrs[0]->newInstance()->v;
}

echo tagOf((new ReflectionClass(App::C::class))->getAttributes()), "\n";
echo tagOf((new ReflectionClass(App::Contract::class))->getAttributes()), "\n";
echo tagOf((new ReflectionClass(App::Helper::class))->getAttributes()), "\n";
echo tagOf((new ReflectionClass(App::E::class))->getAttributes()), "\n";
echo tagOf((new ReflectionMethod("App", "f"))->getAttributes()), "\n";
echo tagOf((new ReflectionProperty("App", "p"))->getAttributes()), "\n";
echo tagOf((new ReflectionClassConstant("App", "K"))->getAttributes()), "\n";

// Bare attribute name resolves module-relative (App::Local), like any bare class ref.
echo tagOf((new ReflectionClass(App::UsesLocal::class))->getAttributes()), "\n";

// Attribute composes with `internal` visibility: the reflection sees the attribute,
// but the class remains gated from outside.
$sec = new ReflectionClass(App::Secret::class);
echo tagOf($sec->getAttributes()), " internal=", var_export($sec->isModuleInternal(), true), "\n";
try { new App::Secret(); echo "LEAK\n"; } catch (\Error $e) { echo "internal still denied\n"; }
?>
--EXPECT--
Tag:cls
Tag:iface
Tag:trait
Tag:enum
Tag:fn
Tag:prop
Tag:const
App::Local:bare
Tag:intern internal=true
internal still denied
