--TEST--
Modules: a module member-class constant/enum-case is usable in a compile-time constant expression
--DESCRIPTION--
A class-constant or enum-case reference whose class is a module member chain
(`M::T::CASE` from outside, or the in-module self form `module::T::CASE`) folds to
the member's canonical class name during constant-expression compilation, exactly as
it does at runtime and for `::class`. This lets such a value be used as a parameter
default, property initializer, or constant value -- previously it failed with
"Dynamic class names are not allowed in compile-time class constant references".
--FILE--
<?php
module M {
    public enum Suit: string { case Spades = 's'; case Hearts = 'h'; }
    public class Card {
        // in-module self-reference in a parameter default
        public function fromInside(M::Suit $s = module::Suit::Spades): string { return $s->value; }
    }
    // module member class constant in a property initializer (const expr)
    public class Deck {
        public M::Suit $default = module::Suit::Hearts;
    }
}

// fully-qualified member chain as a default value from OUTSIDE the module
function fromOutside(M::Suit $s = M::Suit::Spades): string { return $s->value; }

echo (new M::Card)->fromInside(), "\n";                 // s (default via module::Suit::Spades)
echo (new M::Card)->fromInside(M::Suit::Hearts), "\n";  // h (explicit arg)
echo (new M::Deck)->default->value, "\n";               // h (property initializer)
echo fromOutside(), "\n";                       // s
echo fromOutside(M::Suit::Hearts), "\n";        // h
?>
--EXPECT--
s
h
h
s
h
