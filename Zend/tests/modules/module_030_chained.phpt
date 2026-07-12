--TEST--
Modules: chained "Module::Class::member" (static method / constant / property)
--FILE--
<?php
module Vendor\App {
    public class Service {
        public const TAG = "svc";
        public static int $count = 0;
        public static function make(): string { return "made"; }
    }
    public class Registry {
        // from inside the module, the member class is named module-relative (bare)
        public static function tag(): string { return Service::TAG; }
    }
}

// From outside the module, the fully-qualified chain resolves to the member class
echo Vendor\App::Service::make(), "\n";      // static method  -> made
echo Vendor\App::Service::TAG, "\n";          // class constant -> svc
echo Vendor\App::Service::$count, "\n";       // static prop read -> 0
Vendor\App::Service::$count = 5;               // static prop write
echo Vendor\App::Service::$count, "\n";       // -> 5

// From inside the module (member class named module-relative)
echo Vendor\App::Registry::tag(), "\n";       // -> svc
?>
--EXPECT--
made
svc
0
5
svc
