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
  - *Implication (author-confirmed choice):* `::` in the class-table key is
    harmless (the table is a case-insensitive string hash), but it makes module
    member resolution a SEPARATE code path from namespace (`\`) resolution —
    which is the point (the visible boundary is the feature). Obligation: every
    site that consumes a class-name string must be taught `::`. Audit list for
    later phases: `serialize`/`unserialize` `O:` strings, `var_export`,
    `ReflectionClass::getName`, exception/error message formatting, `::class`.
    Backslash keys would reuse all that for free but make module members
    indistinguishable from plain namespaced classes (needs a side-registry) and
    strip `::` from `::class`/errors — rejected for that reason.
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

### Increment 1 (2026-07-07) — parse + register + canonical key. DONE.

Implemented and tested (`Zend/tests/modules/`, 6 phpt, all pass; no
regression in namespaces/lang):

- Tokens `T_MODULE`/`T_INTERNAL` (scanner, `RETURN_TOKEN_WITH_IDENT`);
  semi-reserved via `reserved_non_modifiers` (usable as method/const names —
  verified). BC break is the `match`/`enum` class (global funcs/classes named
  `module`/`internal`).
- `ZEND_AST_MODULE` (2-child: name, body-or-NULL) + grammar for the manifest
  block (`module X { ... }`) and membership declaration (`module X;`), added to
  `top_statement`. Zero new bison conflicts (only the spike's known items,
  none wired yet). AST export case added.
- Module boundary prefixing: `FC(current_module)` threads the enclosing module
  through `zend_prefix_with_ns`, which now yields `<module>::<ns-prefixed name>`
  — so `module Vendor\User { class Profile }` → class-table key
  `vendor\user::profile`, and a membership file `module Vendor\User; namespace
  Auth; class PasswordChecker` → `vendor\user::auth\passwordchecker`. `::class`
  and `get_class()` return the canonical `::` form. The namespace-first check
  (`zend_is_first_statement`) now permits a leading `module X;`, like `declare`.
- Per-request registry `EG(module_registry)` (lazy alloc, freed at
  `shutdown_executor`); `zend_register_module`/`zend_lookup_module` +
  `zend_php_module` struct (name, lc_name, members table stub).
- Guards (all compile-time fatal `E_COMPILE_ERROR`): module must be in root
  namespace; no nesting; module name may not collide with an existing class
  (shared symbol space, ruling #5).

**Taxonomy note (refines the earlier ruling):** the RFC says these violations
raise "ParseError". In the engine, semantic *declaration* violations are
`E_COMPILE_ERROR` fatals (uncatchable), matching how analogous namespace errors
behave ("Cannot use X as namespace name", "namespace must be first"). True
*syntax* errors remain catchable `ParseError`. So "ParseError" in the RFC maps
to E_COMPILE_ERROR fatal for these semantic guards; documented here rather than
inventing a catchable path the rest of the engine doesn't use.

### Increment 2 (2026-07-07) — native `::` syntax + member visibility. DONE.

Implemented and tested (`Zend/tests/modules/`, 9 phpt, all pass; leak-free;
no regression in lang/namespaces):

- **Native `::` class references** (the syntax the RFC actually wants,
  replacing the increment-1 dynamic-string workaround): `module_qualified_name:
  name '::' name` wired into `class_name_reference` (so `new Vendor\User::Profile()`
  parses), `type_without_static` (type hints), and `extends_from`. Built with
  `%expect 1` — exactly the one spike-predicted `instanceof A::B` vs `A::$prop`
  shift/reduce, resolved by default-shift (documented in the grammar). The name
  is built as a canonical `Module::Member` string AST marked `ZEND_NAME_FQ`, so
  resolution looks it up verbatim as the class-table key. Verified: `new`, param
  and return type hints, `extends`, and `instanceof` all work with `::`.
  (`use`-alias resolution of the module segment is still deferred.)
- **Member visibility**: the manifest block is now a RESTRICTED
  `module_member_list` — every member is `public|internal` + a class/interface/
  trait/enum/const declaration. Imperative code is a natural parse error (RFC
  requirement met for free). Visibility is carried on a `ZEND_AST_MODULE_MEMBER`
  wrapper (attr) and recorded per-member in the registry (`mod->members`:
  canonical-lc name → visibility).
- **Enforcement**: `zend_resolve_class_name` (FQ path) now rejects a static
  reference to an `internal` member from outside the owning module with
  `E_COMPILE_ERROR` ("Cannot access internal module member ..."). Inside the
  module (`FC(current_module)` matches), internal access is allowed. Verified:
  `new Billing::Invoice()` (public) works everywhere; `new Billing::Ledger()`
  (internal) works inside `Billing`, compile-errors outside.

**Enforcement scope (honest limits):** the internal check is *compile-time* and
covers *static* references (`new`/type/`extends`) within a compilation unit
where the module is already registered. Dynamic references (`new $string`) and
cross-file references before the owning manifest is loaded are NOT yet gated —
that needs the runtime-prologue membership check + handshake (increment 3).

**Deferred to increment 3+:** `::` in `new`/type/extends is DONE; remaining:
runtime + cross-file internal enforcement via the membership handshake;
two-tier autoload; forward-declaration ("claim") merging + membership-file
members that fill claims; nested modules; `internal` on *class members*
(methods/props) as opposed to module top-level members; module `static`
functions/properties + `module::` self-reference; asymmetric-visibility
interplay; ReflectionModule. Also an audit pass for `::`-name consumers
(serialize `O:` strings, var_export) per the canonical-key implication above.
