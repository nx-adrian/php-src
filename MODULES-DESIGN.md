# PHP Modules — Implementation Design Notes (branch: php-modules)

Working notes for the vertical-slice implementation of the Modules RFC draft
(author: __adrian). This file tracks decisions, deviations from the draft, and
implementation findings; it is not part of the proposal itself.

## Scope of this branch (agreed 2026-07-07)

**Vertical slice**: module manifest parsing + module registry + `::` name
resolution + `public`/`internal` visibility for top-level classes (including
inheritance enforcement) + file membership with runtime-prologue handshake +
two-tier autoloading + tests.

**Deferred** (later phases): `internal` on class members, module-level
`static` functions/properties and `module::` self-reference, `use` aliasing
inside manifests, ReflectionModule, file-private symbols, opcache/preload
integration, JIT awareness.

## Rulings from design review (author-confirmed)

1. `::` stays as the module boundary separator. If a hard grammar/engine
   conflict emerges, document it precisely; fallback is a dedicated token.
2. Handshake is a **runtime prologue**: a membership file's op_array carries
   its module claim; the check runs when the op_array first executes
   (opcache-safe), not mid-compilation.
3. `module static` in the draft's Billing example is a typo for
   `internal static`. Exactly two module-level visibility tiers exist.
4. Vertical slice first; full RFC parity is explicitly multi-phase.

## Defaults adopted (flagged in review, no objection raised)

- **Error taxonomy**: runtime access violations throw `Error` (mirroring
  `private`); declaration/inheritance violations are compile/link-time fatals.
  The draft's `BadMethodCallException`/`RuntimeError`/`RuntimeException`/
  `FatalError` mentions are normalized accordingly.
- **Reflection**: `setAccessible()` and `export()` dropped (no-op since 8.1 /
  removed in 8.0). `internal` is reflection-visible and bypassable exactly
  like `private`.
- **Shared symbol space**: modules and classes share one symbol namespace;
  declaring both `class Billing` and `module Billing` is a fatal error. This
  is what makes `Billing::create()` / `Billing::Invoice` resolvable.
- **Canonical FQN display**: `Vendor\User::Auth\PasswordChecker` (single `::`
  at the module boundary, `\` inside). Class-table keys use the same string,
  lowercased. `::class` yields the canonical form.
- **Tier-2 autoload verification** [OPEN — author undecided 2026-07-07]:
  after the transformed (`::`→`\`) autoload, the engine verifies the loaded
  definition actually declared membership in the module; a plain class at that
  name would be an `Error`. Implementing the strict (verify) version because
  it's the safe default and trivial to relax to lenient later. Revisit before
  any RFC: strict is safer but rejects a legitimate pattern (a module wanting
  to expose an ordinary PSR-4 class it didn't author); lenient is more
  permissive but weakens the boundary guarantee.
- **`use` inside manifests** is module-relative by default per the draft;
  noted as the most ergonomically contested choice (deferred anyway).
- **Overhead framing**: "zero overhead" claim softened to "no cost for
  non-module code; one pointer comparison on guarded paths inside modules".

## Keyword strategy

- `module`, `internal`: new tokens via `RETURN_TOKEN_WITH_IDENT`, added to
  `reserved_non_modifiers` (semi-reserved: method/const names keep working).
  BC break (same class as `match`, PHP 8.0): global functions/classes named
  `module` or `internal`, and calls thereof, become parse errors.

## `::` grammar strategy (the risk item)

Key observation: expression-position `A::B` (const fetch) and `A::b()`
(static call) ALREADY parse — no grammar change is needed there; module
resolution happens at fetch/call time via the shared symbol space. New
grammar is only needed where `::` is illegal today:

- `new A::B` / `new A::B\C` (class_name_reference)
- `instanceof A::B`
- `catch (A::B $e)`
- type positions (params/returns/properties)
- `extends` / `implements` lists
- chained access from outside: `A::B::CONST`, `A::B::method()` (expression
  grammar — highest conflict risk; may be deferred within the slice in favor
  of `use`-style access or Phase 2)

Approach: add `module_qualified_name: name T_PAAMAYIM_NEKUDOTAYIM name` only
in the positions listed, measuring bison conflicts (`%expect 0`) after each
addition. Findings recorded below as they land.

## Findings log

### Grammar spike (2026-07-07) — VERDICT: `::` is viable

Baseline grammar is `%expect 0` clean. Measured bison (3.8.2) shift/reduce
conflicts from adding `module_name: name '::' name` to various positions:

| Position wired | Conflicts | Note |
|---|---|---|
| `class_name` (everything at once) | **2** s/r | worst case; feeds expr + decl |
| `extends_from` only | **0** | declaration position |
| `type_without_static` (type hints) | **0** | declaration position |
| `class_name_reference` (`new` + `instanceof`) | **1** s/r | isolated to instanceof |

**Conclusion:** module-qualified names with `::` are conflict-free in every
*declaration/type* position (extends, implements, type hints, catch, `new`).
The ENTIRE conflict surface reduces to exactly TWO spots, both from `::`
already meaning "member access" in the *expression* grammar:

1. **`instanceof A::B`** (1 s/r): shift → `A::B` module-qualified type;
   reduce → `A::$prop` static-property class expression. LALR(1) must decide
   at `::` without seeing whether `$` or a bareword follows. Bison's default
   (shift) yields the desired module interpretation; cost is shadowing the
   rare `instanceof Foo::$staticPropHoldingClassName`. Documentable.
2. **Chained `A::B::C`** in expression position: ambiguous between
   `(module A::B)::const C` and `(class-const A::B) ::C`. Resolved NOT by new
   grammar but by the engine reinterpreting the EXISTING `class_constant`
   parse tree via the shared symbol table (ruling #5) — `A::B` already parses.

**Therefore `::` stays** (honoring author preference). Key enabling insight:
*expression-position member access needs no grammar change at all.*
`Billing::Invoice`, `Billing::create()`, `Billing::CONST` already parse today
(as class-const / static-call); the engine resolves them against the shared
class/module symbol table at bind/runtime. New grammar is needed only to let a
module-qualified name appear as a *type/class reference* (extends, implements,
type hints, catch, `new`) — and all of those are conflict-free. The lone
`instanceof` conflict is accepted via default-shift and noted in tests.

No dedicated token needed. Deferred within the slice: chained `A::B::C`
resolution (needs engine-side reinterpretation, Phase 2) and `instanceof`
default-shift wiring (add with an explicit test pinning the behavior).
