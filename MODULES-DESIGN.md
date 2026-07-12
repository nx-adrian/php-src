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

### Increment 3 (2026-07-07) — two-tier autoload. DONE.

Implemented and tested (10 phpt total; leak-free; no regression in
classes/autoload/spl/namespaces):

- **Two-tier autoload** in `zend_lookup_class_ex` (the class-resolution
  chokepoint). When a `Module::Member` class is not yet defined:
  - **Tier 1**: if the module isn't registered, autoload it by its bare name
    (`Vendor\User`); the member may be defined inline in the manifest, so
    re-check the class table afterward.
  - **Tier 2**: transform the boundary `::`→`\` and autoload the resulting
    backslash name (`Vendor\User\Auth\PasswordChecker`); the membership sub-file
    registers the class under its canonical `::` key, so re-check the class
    table under that `::` key (NOT the backslash autoload name).
  - **Strict Tier-2 verification** (the design's open item, resolved strict):
    because we look the member up under the `::` key, a plain non-module class
    at the backslash name does NOT satisfy a module reference — returns NULL.
  Verified against a real on-disk PSR-4-style layout with an unmodified
  Composer-shaped autoloader: `new Vendor\User::Profile()` loads via Tier 1
  (inline in manifest), `new Vendor\User::Auth\PasswordChecker()` via Tier 2
  (separate file). The autoloader needs zero module-specific logic.

**Honest gap (documented, deferred to increment 4):** internal-member
enforcement is still compile-time/static only. A member reached purely via
autoload (module not known when the referencing file compiled) is NOT gated —
e.g. `new Vendor\User::Secret()` from outside, where `Secret` is internal and
the module is autoloaded at runtime, currently succeeds. Closing this needs the
runtime membership prologue (a per-op_array module-context check that sets
"currently executing inside module X") so the autoload path can ask "is the
caller inside the owning module?". The out-of-order standalone include of a
membership file (loading a sub-file directly before its manifest) uses that
same prologue and is likewise deferred.

### Increment 4 (2026-07-07) — runtime internal enforcement. DONE.

Implemented and tested (12 phpt total; no regression across 1382 tests in
classes/lang/spl/namespaces):

- **Runtime module-access check** in the three runtime class-fetch entry points
  (`zend_fetch_class_by_name`, `zend_fetch_class`, `zend_fetch_class_with_scope`).
  After a class resolves, if it is a module's `internal` member, access is
  denied unless the currently-executing code belongs to the same module —
  where "current module" is the `<module>::` prefix of the executing function's
  class scope name (`EG(current_execute_data)` walked to the nearest user
  frame). Throws `Error` ("Cannot access internal module member ...").
  Fast-pathed: the check bails immediately for any name without `::`.
- This closes the increment-3 gap on all three fronts, verified:
  - internal member reached via **autoload** (module not compile-time known);
  - **dynamic** `new $string` of an internal member from outside;
  - while still **allowing** internal access (static or dynamic) from code
    executing inside the owning module.

So `internal` is now genuinely enforced at both compile time (static
in-unit references) and runtime (autoloaded / dynamic / cross-file), throwing
the same `Error` message via either path.

### Increment 5 (2026-07-07) — `internal` class methods. DONE.

Implemented and tested (13 phpt; no regression across ~2700 tests in
classes/traits/methods/access_modifiers/spl/lang/namespaces — the hot
method-dispatch path):

- **`internal` as a method visibility modifier** (`member_modifier`), a new
  fn_flag `ZEND_ACC_MODULE_INTERNAL` (function bit 31) stored alongside
  `ZEND_ACC_PUBLIC` (so normal dispatch treats the method as callable) plus the
  marker. Only valid on methods for now.
- **Enforcement** in both method resolvers (`zend_std_get_method`,
  `zend_std_get_static_method`): an internal method is callable only from code
  whose class scope shares the method's module (`<module>::` prefix); otherwise
  `Error` ("Cannot call internal method X::y() from outside its module").
  Guarded by the (rare) marker flag with `UNEXPECTED`, so the hot path pays only
  one flag test. Verified against the RFC's OrderManagement/InventoryEngine
  example: a sibling class in the module calls `reserveStock()` fine; the same
  call from global scope is denied.

**Grammar-conflict finding (design note):** adding `internal` to
`member_modifier` while it was also in `reserved_non_modifiers` produced a
reduce/reduce conflict in trait aliasing (`A::foo as internal` — is `internal`
a rename target or a visibility?). This is inherent: a token cannot be both a
visibility modifier and a free identifier in LALR(1). Resolved by making
`internal` a fully reserved keyword (removed from `reserved_non_modifiers`),
exactly like `public`/`private`. So `internal` is no longer usable as a
method/const/identifier name (a BC break — the same class as `match`). `module`
remains semi-reserved (no such conflict) and is still usable as a member name.
This refutes the RFC's hope that `internal` could stay unreserved "allowed only
where visibility modifiers are parsed" — the trait-alias grammar forces a
choice. Worth stating explicitly in any real RFC.

**Scope note:** `internal` is implemented for methods (the RFC's headline
class-member example). Internal *properties* and *class constants*, and
reaching an internal *static* method via the chained `A::B::C()` syntax (that
chained form is itself deferred — see the spike), are not yet done. Static
internal enforcement works when the class is reached dynamically.

### Pre-RFC hardening: error-message audit (planned, not yet done)

Module resolution reuses engine paths that emit messages phrased for the
non-module world, so a module mistake can produce a technically-correct but
misleading diagnostic (e.g. the increment-8 bug surfaced as `Class "Vendor\App"
not found` for a plain `strtoupper()` call). Before any RFC, sweep the
developer-facing messages on every module failure path — unknown module,
internal access from outside, unclaimed member, chained-`::` not-yet-supported,
a module/class name collision — and ensure each names the module boundary and
the actual problem, rather than leaving a stock "class not found"/"call to
undefined" that points the developer at the wrong thing. Judge by "would a
developer who hit this understand what they did wrong," not just correctness.

### Increment 8 (2026-07-07) — fix: module prefix must not hit function/const names. DONE.

Bug: the module boundary prefix lived in the shared `zend_prefix_with_ns`, which
resolves function and constant names too (via `zend_resolve_non_class_name`), not
just class-like names. So a bare `strtoupper()` inside a module method became
`Vendor\App::strtoupper`, whose `::` the engine then read as a static call —
`Error: Class "Vendor\App" not found`. This broke the global-function fallback
for every bare function/const call inside a module.

Fix: `zend_prefix_with_ns` is namespace-only again; the module prefix moved to a
new `zend_prefix_class_with_module_and_ns`, used only for **class-like** names
(class/interface/trait/enum — they share `zend_compile_class_decl` and
`zend_resolve_class_name`). Functions and constants keep plain namespace
resolution and their global fallback.

Verified: bare `strtoupper/trim/strlen`, rooted `\strtoupper`, and global
constants (`M_PI`) all resolve correctly from a method inside a module; bare
class-like references still resolve module-relative (`implements Drawable` →
`Widgets::Drawable`). Regression test `module_014_function_resolution`; no
regression in namespaces/lang/function tests. (Note: bare *class-like* names are
module-relative with no global fallback — consistent with namespace semantics for
unqualified class names — so a global class inside a module needs a leading `\`.)

### Increment 7 (2026-07-07) — conflict-free `::` (supersedes the %expect 1 tradeoff). DONE.

The increment-2 grammar accepted one shift/reduce conflict (`%expect 1`) by
routing module-qualified names through a standalone `module_qualified_name:
name '::' name` rule fed into `class_name_reference`. That competed with the
stock `class_name '::' simple_variable` rule (static-property-as-class-ref) at
the `::`: reaching the static-prop rule requires reducing `name → class_name`
*before* the `::`, while the module rule shifts `::` keeping `name` — a
reduce/shift fork at a point where the disambiguating token (bareword vs
`$variable`) is not yet visible to the LALR(1) parser. Default-shift resolved it
but turned `new Foo::$staticProp` and `instanceof Foo::$staticProp` (both valid
stock PHP) into parse errors.

**This is now fixed with ZERO conflicts and ZERO BC loss.** The module case is
declared as a *sibling* of the static-property rule inside `new_variable`:

```
new_variable:
    ...
  | class_name T_PAAMAYIM_NEKUDOTAYIM simple_variable   { STATIC_PROP }   /* Foo::$x */
  | class_name T_PAAMAYIM_NEKUDOTAYIM name              { module-qualified } /* Foo::Bar */
```

Both alternatives share the `class_name ::` prefix, so the parser reduces
`name → class_name` unconditionally (both continuations need it), shifts the
`::`, and only *then* branches on the following token — a bareword `name` vs a
`$variable` — which is squarely within one-token lookahead. `module_qualified_name`
is retained only for the pure type/declaration positions (type hints, `extends`),
which were always conflict-free. `%expect` is back to **0**.

Verified end to end: `new Vendor\User::Profile()`, `extends`, `instanceof`, type
hints all work AND `new Holder::$cls` / `(new Foo) instanceof Holder::$cls`
(static-property class references) work again. 14→15 module phpt (added
`module_013_static_prop_coexist`); no regression across lang/classes/namespaces.

**Lesson for the RFC:** the `::` grammar conflict is NOT inherent to reusing the
operator — it was an artifact of *where* the new rule was attached. Sharing the
`class_name ::` prefix with the existing static-property rule eliminates it. The
recommendations doc's "accept the break / use a new token" framing is superseded:
`::` is viable with no conflict and no lost syntax. A dedicated token is not
needed on grammar grounds. (Chained `A::B::C` in expression position is a
separate, still-deferred item and is unaffected by this.)

### Increment 6 (2026-07-07) — ReflectionModule. DONE.

Implemented and tested (14 module phpt; 509 reflection tests pass):

- **`ReflectionModule`** in `ext/reflection`, introspecting a declared module
  via the engine registry: `__construct(string $module)` (throws
  `ReflectionException` for an unknown module), `getName(): string` + a
  `public string $name` property, `getClasses(): array` (canonical member
  names), `getSymbolVisibility(string): string` ("public"|"internal", throws
  for a non-member). Verified end to end.
- Registered with the classic `zend_register_internal_class` +
  hand-written arginfo, deliberately NOT via the stub generator (`gen_stub`
  needs network access, unavailable here). A real PR would use the stub/arginfo
  workflow; noted.
- **Naming-collision finding (design note):** the RFC proposes `isInternal()`
  on `ReflectionClass`/`ReflectionMethod`, but those already inherit
  `isInternal()` meaning "defined by a PHP extension/core" (vs userland). Reusing
  the name would be a semantic collision. A real RFC must pick a distinct name
  (e.g. `isModuleInternal()`); not added here to avoid overloading the existing
  method. `getSymbolVisibility()` covers the introspection need for now.
- **Cross-test impact (the recurring lesson):** adding a class to the
  `reflection` extension changed `ReflectionExtension('reflection')->getClasses()`
  from 26 to 27 entries — updated `ReflectionExtension_getClasses_basic.phpt`
  accordingly. A feature that changes what's enumerable invalidates distant
  tests' premises.

**Deferred to increment 7+:** forward-declaration ("claim") merging +
membership sub-files filling manifest claims; nested modules; `internal` on
*class members* (methods/props) as distinct from module top-level members;
module `static` functions/properties + `module::` self-reference; the
out-of-order standalone-include handshake (loading a membership sub-file
directly before its manifest — the autoload path already orders correctly);
asymmetric-visibility interplay; ReflectionModule; `::`-name consumer audit
(serialize `O:` strings, var_export); `use`-alias resolution of the module
segment; module-level constants/functions visibility enforcement (only classes
are gated so far).

## Increment 10 — persistent (runtime-driven) module registry

**Problem (highest-risk item, now reproduced, not just theorized).** Module
registration happened *only* at compile time (`zend_compile_module` →
`zend_register_module` into the per-request `EG(module_registry)`). Opcache
compiles a file once and, on a cache hit, serves the cached op_array **without
re-running the compiler** — so the module never registers on subsequent requests.
Reproduced with the file cache across two processes: run 2 (mod.inc loaded from
cache) fails `new ReflectionModule("Vendor\App")` with *"Module does not exist"*,
and — worse — internal-access enforcement silently *inverts*: a dynamic
`new "Vendor\App::Secret"()` from outside the module, correctly BLOCKED on run 1,
is ALLOWED on the cached run 2 because the (empty) registry reports no such
member. A compile-time-only guarantee is a security hole under opcache.

**Fix — move registration to a runtime op, carry the roster as a CONST literal.**
The engine already solves this for classes: `ZEND_DECLARE_CLASS` runs at execution
time (even from cache) to populate `EG(class_table)`. Mirror that:
- New opcode **`ZEND_DECLARE_MODULE`** (212), `CONST, CONST` — op1 = module name,
  op2 = member roster array (`lc "module::member"` → `LONG` visibility). Handler
  calls `zend_declare_module_runtime()`.
- `zend_compile_module` builds the roster array while walking the manifest and
  emits the op. Compile-time registration is *kept* (needed for same-file
  compile-time hidden-member checks); the runtime op is idempotent — it only
  (re)builds the roster when the registry entry is empty (i.e. a cache hit).
- **Why no `zend_persist.c` changes are needed:** the roster rides in the
  op_array's *constant table*, and opcache already persists/restores op_array
  constants (arrays included) to SHM and file cache for free. Durability by
  construction; the runtime op re-materialises the per-request registry from it.

**Verification.** The two-process file-cache repro now passes on the cached run
(module found; internal access blocked) — captured as
`Zend/tests/modules/module_015_opcache_persistence.phpt`. All 17 module tests
green; 476 core Zend tests (ns/class/const/trait/enum) green, 0 failures. The 7
`ext/opcache/tests` failures are environmental (`proc_open`/`posix_spawn` can't
launch the CLI-server helper in this sandbox), unrelated to modules.

**Still open (preload).** Preloaded files aren't executed per request, so a
preloaded module's `DECLARE_MODULE` op would not re-run — preload would need the
module registered permanently at preload time (à la `preload_link()` for classes).
Deferred; the common opcache (non-preload) path is now correct. The no-block
membership form (`module Foo;`) emits an empty roster for now (its member-visibility
recording is a separate, still-incomplete path).

## Increment 11 — "module::" self-reference (class-like members)

Adds the `module::Member` self-reference the RFC uses (`new module::Ledger()`,
`public function make(): module::Ledger`, `extends module::Ledger`,
`$o instanceof module::Ledger`). `module::` names a member of the *lexically
enclosing* module — which is unknown at parse time — so resolution is deferred:

- **Grammar.** `T_MODULE '::' name` added as an alternative in `module_qualified_name`
  (covers `extends` and type positions) and in `new_variable` (covers `new` and
  `instanceof`). Conflict-free — `%expect 0` still holds — because `module` is a
  distinct token from any `class_name`, so the added rules share no ambiguous
  prefix. Emits `zend_ast_create_module_self_qualified_name()`, a name zval tagged
  with a new qualification attr **`ZEND_NAME_MODULE_SELF (3)`**.
- **Resolution.** `zend_resolve_class_name` handles `ZEND_NAME_MODULE_SELF` first:
  errors if used outside a module; otherwise prefixes the member with
  `FC(current_module)` and **requires it to be a declared member** of that module
  (checked against the module's roster). This is the first place membership gates
  resolution rather than merely name-prefixing — the explicit `module::X` form
  asserts "X because the module declares it," matching the intended semantics.
  Using attr value 3 is safe: the const-expr `new` path resolves the name (line
  ~12021) before it re-encodes `attr` as `fetch_type << SHIFT` (line ~12031).

Tests: `module_016` (happy path across new/type/extends/instanceof, incl. reaching
an `internal` member from inside), `module_017` (outside-module error), `module_018`
(non-member error). 20 module tests green; 511 grammar/class/new/instanceof/ns/
trait/enum/const tests green, 0 regressions.

**Known gap (pre-existing, not specific to `module::`).** `implements` and
interface-`extends` lists use `name_list`, which accepts neither `module::Member`
nor the two-segment `Module::Member`. Wiring `module_qualified_name` into those
lists is a separate `::`-coverage task (touches the shared `name_list`, used also
by `catch` and trait `use`), deferred to avoid conflict risk. **Not yet done:**
`module::` static functions/properties (`module::foo()`, `module::$x`) — the
module-level statics storage/resolution model (C8) is the next increment.

## Increment 12 (planned) — module-level statics via a synthetic backing class

**Decision (agreed):** represent each module's static members (static functions,
static properties, constants) as a hidden `zend_class_entry` keyed by the plain
module name `M` (no `::`). `M::f()` / `M::C` / `M::$x` (external) and
`module::f()` / `module::C` / `module::$x` (internal) then reuse the engine's
existing static-method / class-constant / static-property storage, dispatch,
opcache persistence, and reflection. Chosen over bespoke module storage for the
code reuse and because module/class already share one symbol space.

**Build approach:** synthesize a class-declaration AST from the module body's
static/const members and run it through `zend_compile_class_decl`, marked
non-instantiable (`new M()` must be rejected — Instantiable Modules is future
scope). Module `internal static` members carry `ZEND_ACC_MODULE_INTERNAL` so the
existing runtime same-module enforcement applies unchanged.

**Known friction to handle (found while scoping):**
- *Naming.* `zend_compile_class_decl` prefixes every class name with the current
  module (`zend_prefix_class_with_module_and_ns`, ~line 9658). The backing class
  must be named plainly `M`, not `M::M`. Fix: exempt the module's own backing
  class from prefixing (special-case), while still compiling its method bodies
  with `FC(current_module)` set so bare/`module::` names inside them resolve.
- *Grammar.* The module body does not yet parse `static function` or `static`
  property (only class-like decls + `const`). Add these to `module_member`.
  Add `module::` in call/prop/const positions (`T_MODULE :: member_name
  argument_list`, `T_MODULE :: simple_variable`, `T_MODULE :: identifier`) —
  increment 11 only added `module::` in class-reference position.
- *Non-instantiability + collision.* Creating class `M` means `new M()` and a
  later user `class M {}` must both error cleanly (the latter already does via the
  shared-symbol check; the former needs the non-instantiable flag).

**Suggested sub-slices (commit at each green checkpoint):** (a) backing class +
module constants (`M::C`, `module::C`); (b) module static functions (`M::f()`,
`module::f()`); (c) module static properties (`M::$x`, `module::$x`). Static
properties last (initialization/storage is the heaviest piece).

### Increment 12 ruling — no member-name uniqueness restriction (agreed)

Member names may overlap freely across kinds: a module constant, static function,
static property, and member class may all share a name (and const/fn/prop may
share names with each other). **No cross-check, no "used names" tracking.**

Rationale: with the synthetic backing class, each `M::Name` occurrence resolves in
exactly one grammatical position, and each position consults exactly one table —
value position → backing CE `constants_table`; class-reference position
(new/extends/instanceof/type) → the member class in the class table;
`M::Name()` → `function_table`; `M::$name` → static-members table. No single
position accepts two interpretations, so overlapping names can never cause a wrong
resolution. This is identical to how a normal class already lets `const X`,
`function X()`, and `$X` coexist. An artificial uniqueness rule was considered and
**rejected**: it protects against no real conflict, would surprise users, and
diverges from class semantics for no benefit. Sub-slice (a) therefore just
populates the backing CE's tables; resolution stays position-directed.

## Increment 12(a) — module constants via the synthetic backing class

First slice of module-level statics. Module-level `const` members no longer become
global constants; they become **class constants of a synthetic backing class**
named plainly after the module.

- **Construction.** `zend_compile_module` partitions members: class-like decls
  compile as before (member classes keyed `M::X`); `ZEND_AST_CONST_DECL` members
  are gathered into `ZEND_AST_CLASS_CONST_GROUP`s and wrapped in a synthesized
  `ZEND_AST_CLASS` decl, compiled via `zend_compile_top_stmt`. Because it's a real
  class entry, it inherits registration, the runtime `DECLARE_CLASS` op, opcache
  SHM/file-cache persistence, and reflection for free — `M::C` survives cache hits
  with zero extra work (test module_020).
- **Naming friction resolved.** The backing class is compiled *after* the member
  loop clears `FC(current_module)`, so `zend_prefix_class_with_module_and_ns` leaves
  the name plain `M` (not `M::M`). Asserted.
- **Access.** `M::C` (external) parses as an ordinary class-const fetch — no new
  grammar. `module::C` (internal self-reference) added: `T_MODULE :: identifier` in
  the `class_constant` production, via a bare-`module` backing-class reference
  (`zend_ast_create_module_backing_name` → empty-member `ZEND_NAME_MODULE_SELF`,
  which `zend_resolve_class_name` maps to the plain module name, no membership
  check). `%expect 0` still holds. `module::class` correctly yields `"M"`.
- **Non-instantiable** via `ZEND_ACC_EXPLICIT_ABSTRACT_CLASS` — `new M()` throws.
- **Name overlap works as ruled:** `const Invoice` + `class Invoice` coexist; the
  constant answers in value position, the member class in class-reference position.

Tests module_019 (functional) + module_020 (opcache persistence). 20→22 module
tests green; 505 core const/class/ns/grammar/enum/trait tests green.

**Known limitations (follow-ups):**
- `new M()` message says "abstract class M" — should be module-specific (needs a
  `ZEND_ACC_MODULE`-style flag + guarded message; also to block `extends M`, which
  abstract currently permits).
- `internal const` is not yet enforced — all module constants are currently mapped
  to public. Internal-constant enforcement needs the class-const fetch path + flag
  handling (deferred with the rest of internal-static enforcement).
- Modules with no static members create no backing class, so `class M` after a
  static-less `module M` does not collide (pre-existing shared-symbol gap;
  always-creating an empty backing class would close it — a small (B)-leaning step).
- Next slices: (b) static functions `M::f()` / `module::f()`; (c) static properties
  `M::$x` / `module::$x`.

## Increment 12(a) follow-ups — ZEND_ACC_MODULE flag, messages, module-scope generalization

Cleanups on top of 12(a):

- **`ZEND_ACC_MODULE` (class-flag bit 31).** The backing class now carries a
  dedicated flag instead of `ZEND_ACC_EXPLICIT_ABSTRACT_CLASS`. Added to the
  `ZEND_ACC_UNINSTANTIABLE` mask, so `new M()` throws **"Cannot instantiate
  module M"** (not "abstract class"). The inheritance check rejects extending it
  with **"Class X cannot extend module M"**. (module_021, module_022.)
- **Shared-symbol check is now module-aware.** `zend_compile_module` ignores an
  existing class named M when it is the module's own backing class
  (`ZEND_ACC_MODULE`); a real user `class M` still collides. This prevents the
  backing class from being mistaken for a foreign collision on module re-entry.
- **`zend_module_scope_allows` generalized + exported.** It now derives a class's
  module from either the `Module::` name prefix (member classes) *or* the backing
  class's own name (`ZEND_ACC_MODULE`). Same behavior as before for internal
  *methods* (member classes are unchanged), and ready for backing-class member
  enforcement. Now `ZEND_API`, declared in zend_compile.h.

**Deferred — internal enforcement of module static members (constants now;
functions/properties later).** `internal const` is currently compiled as public.
Enforcing it properly requires gating **three** routes, exactly as internal
*methods* do: compile-time constant folding (`zend_verify_ct_const_access`), the
`ZEND_FETCH_CLASS_CONSTANT` VM handler (cache-slot aware), and
`zend_get_class_constant_ex` (used by `constant()`/reflection). A partial gate on
only one route is a security footgun (blocks `constant()` but not a folded
`M::SECRET`), so this is deferred to a single dedicated "internal static-member
enforcement" increment covering constants, functions, and properties uniformly
with the generalized `zend_module_scope_allows`. The groundwork (flag + helper)
is in place.

**Also deferred — always-create backing class** (so static-less modules reserve
their name): interacts with the membership/re-declaration lifecycle (a `module M;`
membership file must not collide with, nor recreate, the manifest's backing
class), so it belongs with the forward-declaration/claim work. Documented in 12(a).

## Increment 12(b) — module static functions

Module-level `static function` members become static methods of the backing class.

- **Grammar.** `module_member` gains `member_visibility T_STATIC function ...`
  (static is mandatory per the RFC; a non-static `function` in a module body is a
  parse error). Produces a `ZEND_AST_METHOD` wrapped in `ZEND_AST_MODULE_MEMBER`.
  `module::f(...)` added to the static-call production (`T_MODULE :: member_name
  argument_list`, via the bare backing-class ref). `M::f()` external needs no new
  grammar. `%expect 0` holds.
- **Routing.** `zend_compile_module` partitions `ZEND_AST_METHOD` members into the
  backing class (like constants). Internal ones get `ZEND_ACC_MODULE_INTERNAL`.
- **Internal enforcement is essentially free.** Static-method dispatch already
  gates `ZEND_ACC_MODULE_INTERNAL` through `zend_std_get_static_method ->
  zend_module_scope_allows` (now backing-class-aware). So `Billing::secret()` from
  outside throws "Cannot call internal method ... from outside its module" with no
  new code — unlike constants, methods are never compile-time folded, so a single
  runtime gate suffices.

### Naming friction resolved, and a semantics clarification

The backing class is now compiled with **`FC(current_module)` still set**, because
its method bodies (and const expressions) must resolve module-relative names
(`module::X`, bare member classes). The backing class's own name is kept plain
("Billing", not "Billing::Billing") because `zend_compile_class_decl` skips module
prefixing for a `ZEND_ACC_MODULE` decl.

Consequence (intended, consistent with namespaces): inside module M, a reference to
the module's **own full name** `M::X` module-prefixes to `M::M::X` — exactly as
`Foo\Bar` inside `namespace Foo` becomes `Foo\Foo\Bar`. The idiomatic self-reference
is `module::X`. `module_019`'s `DERIVED` const was updated from `Billing::RATE` to
`module::RATE` to reflect this (its previous success relied on the backing class
being compiled without module context, which no longer holds now that method bodies
need it).

Test module_023. 24 module + 524 core (class/method/static/grammar/ns/trait/const/
enum) tests green. Follow-ups: static **properties** (slice c); internal **const**
enforcement (still deferred); a clearer parse error for a non-static module function.

## Increment 12(c) — module static properties (public); internal statics deferred

Module-level `static` properties become static properties of the backing class.

- **Grammar.** `module_member` gains `member_visibility T_STATIC
  optional_type_without_static property_list ';'` (a `ZEND_AST_PROP_GROUP` wrapped
  in `ZEND_AST_MODULE_MEMBER`). `module::$x` added to `static_member`
  (`T_MODULE :: simple_variable`, via the bare backing-class ref). `M::$x` external
  needs no new grammar. `%expect 0` holds.
- **Routing.** `zend_compile_module` partitions `ZEND_AST_PROP_GROUP` members into
  the backing class; typed props, defaults, read/write, and `module::$x` self-ref
  all work through the normal static-property machinery.

### The 16-bit attr wall (important finding) → internal statics deferred cleanly

Internal static **properties** and **constants** are rejected with a clear compile
error ("... not yet supported; declare it public") rather than shipped unenforced.
Root cause: `zend_ast->attr` is **16 bits**, so `ZEND_ACC_MODULE_INTERNAL` (bit 31)
cannot ride in a prop-group/const-group's `attr` — it truncates to 0 (confirmed by
debug: an "internal" static prop reached `property_info->flags = 0x11`, i.e.
PUBLIC|STATIC only). Internal static **functions** are unaffected because methods
carry flags in the 32-bit `zend_ast_decl->flags`, which is why 12(b) enforced them
for free.

The **runtime gate for internal static properties is already implemented** and
correct (`zend_std_get_static_property_with_info` now checks
`ZEND_ACC_MODULE_INTERNAL` via `zend_module_scope_allows`, same slow path as
private/protected so cache-slot reuse is fine) — it is simply dormant until a
property can actually carry the flag.

**Deferred work (one dedicated increment): internal static-member enforcement for
properties + constants.** The missing piece is a channel to convey per-member
internal-ness into backing-class compilation, since the 16-bit attr can't. Plan:
before compiling the backing class, record the set of internal static-member names;
after compilation, set `ZEND_ACC_MODULE_INTERNAL` directly on the backing CE's
`properties_info` / `constants_table` entries (capturing the CE — e.g. via the RTD
key or a small capture hook). Properties then enforce immediately (single runtime
gate, already in place); constants additionally need the three-route gate
(compile-fold + FETCH_CLASS_CONSTANT VM handler + zend_get_class_constant_ex).

Tests module_024 (public static props), module_025 (internal rejected). 25 module +
732 core (class/static/grammar/ns/trait/const/enum/property) tests green.

## `internal` flag design — decisions & constraints (for the props/consts increment)

**Chosen approach: a permanent, context-specific low bit** for `internal` on
properties and constants (vs. a transient marker translated to bit 31). The ACC
flag space is already context-dependent (e.g. bit 28 is ENUM for a class but
OVERRIDE for a function), so this is idiomatic. A low bit (<=15) is required
because the flag must survive the parse-time carrier `zend_ast->attr`, which is
`uint16_t`; only bits 13/14/15 are free in *both* the property and constant flag
contexts. Methods keep a full-width function flag (they ride `zend_ast_decl->flags`,
32-bit — no attr limit). Rationale over the marker: no translation step to miss
(the marker fails *open* if a translation site is skipped), permanent flag visible
in the final `property_info`/const flags, same near-zero runtime cost.

**Bug found & fixed while choosing the bit:** `ZEND_ACC_MODULE_INTERNAL` (methods)
was `1u<<31`, aliasing `ZEND_ACC_STRICT_TYPES`; every method in a strict_types file
read as internal. Moved to function bit 30 (the last free one). This is why the
method flag is bit 30 and the prop/const flag is a *separate* low-bit constant.

**Semantics locked (confirmed with author):**
- `internal` is scoped to the module **definition**, not the module *instance* — it
  is a code-location predicate ("reachable only from code inside this module"),
  independent of whether the member is static or per-instance. This is what lets the
  same flag+gate serve instance members unchanged when modules become instantiable.
- `internal` keeps its exact meaning across the static→instance shift; the `module::`
  accessor resolves both static and instance members (they share the class symbol
  table), so no new concept is needed there.

**Constraints to keep the door open for instantiable modules (future scope):**
- Treat `ZEND_ACC_MODULE` as an **identity** flag only ("this class *is* a module").
  Its current uninstantiability is a *phase policy* (membership in
  `ZEND_ACC_UNINSTANTIABLE`), to be simply dropped when modules become instantiable
  — like "static class" is an observation, not a separate kind. Do NOT bake
  "module ⇒ uninstantiable" into the flag's meaning or into enforcement code.
- Keep module-*mechanism* flags in the **class** bit-space (roomy `ce_flags` /
  `ce_flags2`), reserving the scarce low prop/const `attr` bits for genuine
  per-member visibility. The RFC's `export` reads as a class↔module relationship, so
  it should be a class flag and not compete for those bits.

## Increment 13 — internal properties (permanent context-specific low bit)

Implements `internal` on properties (static and instance) via the agreed permanent
context-specific low bit, and fixes the member-class assertion crash.

- **Flag.** `ZEND_ACC_MODULE_INTERNAL_MEMBER (1 << 14)` — property + constant
  context. Low bit so it survives the 16-bit compile-time `zend_ast->attr`; distinct
  from the method flag `ZEND_ACC_MODULE_INTERNAL` (function bit 30). No translation
  step: it is the permanent flag, visible in the final `property_info->flags`.
- **Modifier.** `zend_modifier_token_to_flag` now maps `T_INTERNAL` to
  `ZEND_ACC_PUBLIC | ZEND_ACC_MODULE_INTERNAL_MEMBER` for PROPERTY and CONSTANT
  targets (previously only METHOD), and `zend_modifier_token_to_string` handles
  `T_INTERNAL` — this removes the assertion crash that hit any `internal`
  property/constant on a member class.
- **Routing.** The backing-class partition sets the low bit on internal static
  props/consts (fits the attr) instead of rejecting.
- **Enforcement (properties, fully done):**
  - static: `zend_std_get_static_property_with_info` checks the low bit.
  - instance: both `zend_get_property_offset` (hot VM path) and
    `zend_get_property_info` (reflection/API) gate on the low bit via
    `zend_module_scope_allows` — a single UNEXPECTED branch each, before the
    private/protected block (internal is public, so that block wouldn't catch it).
    Covers read and write; cache-slot reuse is safe (per-call-site scope, same as
    private/protected).
- **Constants still deferred.** `internal const` is now flag-plumbed but
  `zend_compile_class_const_decl` rejects it with a clean error — const enforcement
  needs the three-route fetch gate (fold refusal + FETCH_CLASS_CONSTANT VM handler +
  zend_get_class_constant_ex), which is the remaining piece.

Result: internal now works for member classes, methods, and properties (static +
instance); only internal *constants* remain. Tests module_027 (properties),
module_025 (const still rejected). 29 module + ~1700 core (property/object/
reflection/type/ns/class/trait) tests green across this and the strict_types fix.

## Increment 14 — internal constants (three-route fetch gate) — `internal` complete

Completes `internal` visibility. A class constant can be reached by three routes,
all of which now gate on `ZEND_ACC_MODULE_INTERNAL_MEMBER`:

1. **Compile-time folding.** `zend_verify_ct_const_access` returns 0 for an internal
   constant accessed from outside the module (refusing to fold), deferring to the
   runtime opcode; from inside it folds like any public constant.
2. **VM handler `ZEND_FETCH_CLASS_CONSTANT`.** Gates right after the existing
   private/protected `zend_verify_const_access`, using `EX(func)->op_array.scope`.
   Cache-slot reuse is safe (per-call-site scope, like private/protected).
3. **`zend_get_class_constant_ex`** (used by `constant()` and reflection value
   reads that go through visibility). Gates after `zend_verify_const_access`.

All emit "Cannot access internal module constant M::C from outside its module".
The compile-time rejection stub is removed.

**Reflection intentionally bypasses**, exactly as it does for `private` — verified
that `ReflectionClassConstant::getValue()` reads a private constant's value too.
This matches the revised RFC ("reflection reads/invocations bypass internal exactly
as private").

Test module_025 (renamed to `module_025_internal_constants`) covers all routes +
the reflection bypass. 29 module + 461 constant/enum/const-expr/trait tests green.

**`internal` is now complete** across every member kind: module member classes,
methods (module-level static functions and member-class methods), properties
(module-level static and member-class instance), and constants — each enforced at
compile and runtime, with the strict_types bit collision fixed. Remaining module
roadmap items are unrelated to `internal`: chained `::` (C6 → nested modules C7,
`Module::Class::member`), preload persistence, and the forward-declaration/claim
membership handshake.

## Preload persistence — migrate module metadata onto class entries (approach B)

**Problem.** The registry (`EG(module_registry)`) is per-request, populated by the
runtime `ZEND_DECLARE_MODULE` op. Preloaded files execute once at startup and their
op_array body does not re-run per request, so the op never fires and the per-request
registry is empty for preloaded modules — internal enforcement inverts, ReflectionModule
fails, tier-1 autoload misfires. The increment-10 opcode fix cannot help (the opcode
doesn't run). Fix: move the *runtime* source of truth off the side registry and onto the
class entries, which opcache/preload already persist.

**Verification (done): CE-resident info is sufficient for every runtime consumer.**
- Object-handler gates (methods, static+instance props): already CE-resident
  (`zend_module_scope_allows` on CE names/flags). No change.
- `zend_module_runtime_access_denied` (class fetch): member internal-ness + caller
  module → member-CE flag + scope name prefix.
- Tier-1 autoload ("is module M loaded"): existence of the module's backing class
  (needs P2: always-create backing class).
- ReflectionModule: backing CE + member-CE flags + class-table scan for enumeration.
- Compile-time consumers (`zend_module_member_is_hidden`, `module::Member` gate) run
  during compilation (once, at preload for preloaded files) — not broken by preload;
  they can stay on a transient compile-time registry.

**P1 (done).** `ZEND_ACC2_MODULE_INTERNAL` (ce_flags2 bit 0) — a member class declared
`internal` carries it on its persisted CE. Set via a transient `FC(current_member_internal)`
signal read in `zend_compile_class_decl` (the RTD key can't be reconstructed post-compile
— it embeds a monotonic counter — so a compile-context signal is used instead).
`zend_module_runtime_access_denied` now takes the already-fetched `ce` and checks the
flag via `zend_module_scope_allows` — no registry lookup. 29 module + 327 class/autoload/
ns tests green.

**Remaining:** P2 always-create backing class + migrate tier-1 autoload and
ReflectionModule-exists to backing-class existence; P3 migrate ReflectionModule
enumeration/visibility to CE data, make the registry compile-time-only, then build with
preload and verify a preloaded module enforces per request.

## Preload migration P2 (done) — always-create backing class + CE-based tier-1 autoload

- **Every module manifest now materializes a backing class** (`ZEND_ACC_MODULE`), even
  with no static members. It is the module's runtime identity/presence marker — a
  persisted class entry, so it survives opcache/preload where the per-request registry
  does not. Bonus: the shared-symbol rule is now fully bidirectional (a plain `class M`
  collides with any `module M`, not just modules that happened to have statics).
- **Tier-1 autoload** ("is module M loaded?") now checks for the backing class
  (`zend_hash_find_ptr(EG(class_table), mod_lc)` + `ZEND_ACC_MODULE`) instead of
  `zend_lookup_module`. Preload-safe. module_008 (two-tier autoload) still green.

Non-instantiable / non-extendable behavior unchanged (member-only modules included).
Test module_028. 30 module + 654 class/autoload/ns/trait/enum + 508 reflection green.

Remaining: P3 — migrate ReflectionModule (getName/getSymbolVisibility/getClasses and
the construct-time exists check) to CE data (backing CE + member-CE flags + class-table
scan), make the module registry compile-time-only, then build with preload and verify.

## Preload migration P3 (done) — reflection to CE data + optimizer gate + preload verified

- **ReflectionModule migrated off the registry** to the backing class entry:
  construct/getName use the `ZEND_ACC_MODULE` CE; getClasses enumerates class-table
  keys `Module::Name` (skipping RTD keys and nested chains); getSymbolVisibility reads
  the member CE's `ZEND_ACC2_MODULE_INTERNAL`. Registry-free → works under preload.
- **Fourth const route found and gated (opcache optimizer).** The preload test leaked
  an internal constant: opcache's optimizer (`zend_fetch_class_const_info`) folds a
  const's value into other op_arrays and only refused non-public consts — an internal
  const is public+marker, so it folded, bypassing the runtime gate (visible only when
  the const's class is preloaded/immutable and the referencing file is optimized).
  Added the `ZEND_ACC_MODULE_INTERNAL_MEMBER` + `zend_module_scope_allows` refusal
  there. (So internal-const enforcement now spans FOUR routes: compile fold, optimizer
  fold, VM handler, and zend_get_class_constant_ex.)
- **Preload verified end-to-end** (`module_029_preload.phpt`): a preloaded module whose
  file is never required by the request — so `ZEND_DECLARE_MODULE` never runs and the
  per-request registry is empty — still enforces internal on classes AND constants,
  and ReflectionModule works. This is the definitive proof the runtime is registry-free.
- Test hygiene: the opcache-file-cache tests (015/020) now clean their temp dirs
  recursively (opcache mirrors the full absolute source path, so the old fixed-depth
  glob left stale bytecode).

**Preload persistence is solved.** Every runtime consumer (object-handler gates,
class-fetch enforcement, tier-1 autoload, constants across all four routes,
ReflectionModule) reads CE-resident data; the module registry is now used only at
compile time. The `ZEND_DECLARE_MODULE` opcode + per-request registry rebuild are no
longer read at runtime and could be removed as a later cleanup (left in as a harmless
compile-time/non-preload convenience for now). 31 module + reflection/optimizer/const
suites green.

## Chained "::" (C6) — empirical finding: needs dedicated grammar design, not a rule add

Goal: `Module::Class::member` (static method/const/prop on a *member class*), and later
`X::Y::Z` for nested modules (C7). Today `Vendor\App::Service::make()` misparses —
`Vendor\App::Service` is taken as a backing-class *constant* fetch ("Undefined constant
Vendor\App::Service"), not as the member class whose method is `make`.

**Tried the obvious thing and measured it:** adding
`module_qualified_name T_PAAMAYIM_NEKUDOTAYIM member_name argument_list` (and the const/
prop analogues) as the static-access class. Result from bison: **1 shift/reduce + 38
reduce/reduce conflicts.** Root cause: `module_qualified_name` is `name '::' name`, which
overlaps the existing `class_name '::' member_name|identifier|simple_variable` rules —
at `A '::' B` with lookahead `::`, the parser cannot decide (within LALR(1)) between
reducing `A::B` as a class-constant/static access and continuing a chain. Reverted;
grammar is back to `%expect 0`. This confirms the recommendations-doc prediction that
chained `::` "needs engine-level reinterpretation of the existing parse tree, not new
grammar."

**Not blocking:** the workarounds are clean — inside a module use bare `Class::method()`
(module-relative, works today); outside use `use Vendor\App::Service; Service::method()`
or `$x = new Vendor\App::Service(); $x::method()`. C6 is an ergonomics/completeness gap,
not a functional blocker.

**Viable approaches for a dedicated pass (in preference order):**
1. *Shared-prefix sibling rules* (the increment-7 technique, extended): inline the
   chained forms so they share the `class_name '::'` left prefix with the existing
   single-`::` rules, and branch only on the token *after* the segment (`(` → method,
   `::` → deeper chain, `$` → static prop). The overlap to resolve is `member_name`
   vs `name` vs `identifier` after `class_name '::'`; drive it to zero with
   `bison -Wcounterexamples`. Highest chance of `%expect 0`, most fiddly.
2. *Parse-tree reinterpretation*: allow `class_const '::' member` to parse into a nested
   tree `(A::B)::C`, then in the compiler detect when the inner node resolves to a
   module-qualified class name and rewrite it as the class. Fewer grammar rules, but
   moves complexity into zend_compile.c and still needs a non-conflicting chaining rule.
3. *Nested-module unification*: since C7 also needs `X::Y::Z`, design the multi-segment
   name once (a segment-list node) with the "module = everything before the last `::`"
   resolution rule, used uniformly by class-reference and static-access positions.

Recommend (1) or (3) as a focused increment with conflict-counterexample iteration —
not a tail-end addition. Everything else on the branch remains green (`%expect 0`).

## Chained "::" (C6) — implemented via compiler reinterpretation (approach 2)

`Module::Class::member` now works: static method, class constant, and static property
(read + write). No grammar change — `A::B::C` already parses as a class-constant fetch
`(A::B)` used as the class expression of the outer access, so `%expect 0` is untouched.

**Mechanism:** `zend_try_module_chain_class`, called at the top of
`zend_compile_class_ref` (the single choke point for the class operand of static calls,
class-const fetches, and static-prop fetches). If the class operand is a bareword
`CLASS_CONST(A, B)` and the resolved leading segment `A` is a module (its backing class
`ZEND_ACC_MODULE` is visible, or the module is registered this compilation), it returns
the canonical member-class name `"A::B"`, which the caller uses as a fully-qualified
class reference. Otherwise it returns NULL and stock semantics are preserved.

**Verified:** `Vendor\App::Service::make()/::TAG/::$count` (read+write); internal
enforcement holds through the chain (internal member class denied at class fetch;
internal method/const of a public member class denied at dispatch); works under
**preload**; and stock `Cfg::CLS::method()` (a real constant's value used as a dynamic
class) is untouched. Ownership: the helper returns an owned string transferred to the
result znode — no stray AST node, leak-free (confirmed on the debug build). Tests
module_030 (happy path), module_031 (internal via chain). 33 module + 479 const/class/
static/ns/trait/enum tests green.

**Known limitations (follow-ups):**
- `Module::Class::class` (the `::class` magic on a chain) is not reinterpreted — it
  parses to `ZEND_AST_CLASS_NAME`, a different path than `zend_compile_class_ref`.
- *Cross-file:* reinterpretation needs the module known at compile time of the
  referencing file (same file, preloaded, or registered this request). A chain to a
  member class whose module is only autoloaded later falls back to stock (fails);
  workaround: ensure the module is loaded/preloaded, or `use` the member class.
- *Depth:* only a 2-segment class part (`A::B`) is reinterpreted. Deeper chains
  (`A::B::C::D`) need iterating the nested `CLASS_CONST` — this is the natural hook for
  **nested modules (C7)**, which also require nested-module *declaration* (still
  rejected today). C6 provides the resolution groundwork; C7 declaration is separate.

## internal enforcement is uniformly RUNTIME (compile-time hidden check removed)

Removed the compile-time check (`zend_module_member_is_hidden` in
`zend_resolve_class_name`) that rejected any *static* reference to an internal member.
That check fired during compilation, so `new Module::Internal()` in never-executed code
(an `if (false)` branch or an uncalled function) was a compile fatal — inconsistent with
PHP, where visibility is a runtime property (a `private` const in dead code does not
error). Now internal-member access is enforced solely by the runtime gates (class fetch
/ method-dispatch / const-fetch / property-fetch), so a reference errors only when it
actually executes, matching private/protected exactly.

This is *uniform* — instantiation, access, AND `extends` are all runtime (an internal
parent errors when the subclass declaration executes, i.e. when reached). Chosen over
the recommendations-doc split (access=runtime, extends=compile) for consistency and to
avoid erroring on dead code; the eager compile-time catch is left to static analysis,
as it is for private/protected. `zend_module_member_is_hidden` deleted (now unused).
`module_006` updated to assert the runtime behavior (dead-code no-error + reached-error).
33 module + 384 class/ns/const/autoload tests green.

## Constructor visibility — internal `__construct`

A public class may have an `internal` constructor: visible/typeable everywhere,
instantiable only from inside the module. `zend_std_get_constructor` only checked
non-public constructors, so an internal (public+marker) constructor slipped through
and `new` succeeded from outside. Added a module-scope gate there: if the constructor
carries `ZEND_ACC_MODULE_INTERNAL` and the caller is outside the module, throw
"Cannot instantiate class M::X via internal constructor from outside its module".
Class-fetch gating already denies internal *classes* before the constructor is even
consulted, so the (class visibility × constructor visibility) matrix resolves as: an
internal class denies first; a public class with an internal constructor denies at
construction. Test module_032. 34 module + 814 object/class/reflection tests green.

## Nested modules (C7) — flat boundary model

`module Outer { public module Inner { … } }` now declares a nested module named
canonically `Outer::Inner`, with members `Outer::Inner::member`. Per rec #11 this is a
pure naming boundary — nesting grants no access relationship (no `parent::`, no implicit
access to the enclosing module's internals).

- **Grammar:** `module_member` gains `member_visibility T_MODULE namespace_declaration_name
  '{' module_member_list '}'` → a `ZEND_AST_MODULE` node wrapped as a member. `%expect 0`.
- **Compile:** `zend_compile_module` qualifies a nested module's name with the enclosing
  module (`Outer` + `::` + `Inner`), and saves/restores `FC(current_module)` around the
  nested block so members after it stay owned by the outer module. Backing class
  `Outer::Inner` (ZEND_ACC_MODULE); members prefixed `Outer::Inner::…`.
- **Resolution:** the chained-`::` helper (`zend_module_chain_canonical`) was generalized
  to arbitrary depth — it walks the nested `CLASS_CONST` chain, resolves only the root
  segment, and reinterprets when the "module = everything before the last `::`" prefix is
  a real module. So `Outer::Inner::IV` / `::make()` / `Outer::Inner::Gadget::tag()` resolve.

Verified: nested declaration, backing class, nested constants/static-functions, static
access on nested member classes, and ReflectionModule("Outer::Inner"). Test module_033.
34 module + 142 grammar/class/ns/const tests green.

**Gaps (grammar in the conflict-prone class-reference position; follow-ups):**
- `new Outer::Inner::Gadget` / `instanceof` / `extends` — the 3-segment class-*reference*
  form does not parse (only 2-segment `Module::Member` is wired into new_variable). Static
  access works; for instantiation the workaround is a dynamic name
  (`$c = "Outer::Inner::Gadget"; new $c;`).
- `use Outer::Inner::Gadget;` (and `use Module::Member` generally) does not parse — the
  RFC's absolute-`use` import of module members is unimplemented.
- `internal module Inner` parses (visibility carried) but submodule-hidden-outside-parent
  is not yet enforced — the nested backing class isn't flagged internal.

---

## Increment: arbitrary-depth `::` in class-reference positions (Feature 1)

**Goal:** make `Name::Member` — and chained `A::B::…::Member` to *any* depth — work in
every class-reference position (`new`, `instanceof`, `extends`, `implements`, and
parameter/return/property type declarations), not just static access. Previously only a
single `::` (`Module::Member`) was wired into `new_variable`; `new Outer::Inner::Gadget`
did not parse.

**Key realization:** arbitrary depth already worked in expression/const/static-call/
static-property positions (they self-recurse through `class_constant`/`variable_class_name`,
and the compiler's `zend_try_module_chain_class` walks the chain to any depth). The gap was
isolated to the class-reference grammar, which hard-capped at one `::`.

**Grammar (`zend_language_parser.y`):**
- `module_qualified_name` made left-recursive (`module_qualified_name :: name`) — covers
  `extends` and type positions to arbitrary depth.
- `new_variable` gained a subsequent-hop rule (`new_variable :: name`) so `new`/`instanceof`
  chain; the "name vs `$var`" one-token lookahead keeps it distinct from the static-property
  hop, so `%expect 0` still holds (bison reports zero conflicts).

**Compiler (`zend_compile.c`):**
- `zend_ast_create_module_qualified_name` now (a) guards a non-string left operand
  (`new $x::Foo` -> clean "Illegal class name"), and (b) preserves a `module::`-relative
  chain (`ZEND_NAME_MODULE_SELF`) across hops so `module::Inner::Gadget` stays relative.
- The `module::` self-reference membership check was generalized to derive the owning module
  from the **last** `::` (so `module::Inner::Gadget` verifies against sub-module `Outer::Inner`).

**Enforcement bug surfaced + fixed (`zend_object_handlers.c`):** `zend_module_scope_allows`
derived a member's owning module from the **first** `::`, mis-attributing `Outer::Inner::Secret`
to module `Outer` instead of `Outer::Inner`. Chained access was the first path to exercise
this. Fixed to split at the last `::` (new helper `zend_module_owner_last_sep`), used for both
the member and the accessing scope.

**Autoload bug surfaced + fixed (`zend_execute_API.c`):** the tier-2 `::`->`\` transform used
`zend_string_dup`, which returns the *same* interned buffer for an interned literal — so the
in-place transform corrupted the shared `"Module::Member"` literal (visible as `new A::C`
reporting `Class "A\CC" not found`). Fixed to always allocate a private copy via
`zend_string_init`, and the module two-tier logic is now gated on the prefix genuinely being a
module (`ZEND_ACC_MODULE`); a plain `A::C` falls through and is reported cleanly as
`Class "A::C" not found`.

**BC note (now documented in the RFC):** `Name::bareword` in a class-reference position is now
valid syntax, so `new A::C` on an ordinary class changes from a *parse* error to a *runtime*
"class not found" `Error`. Two upstream tests
(`Zend/tests/new_without_parentheses/*constant`, `*static_method`) asserted the old parse
error and were updated. Expression-position `A::C` (class-constant fetch) is unchanged.

**Tests:** `module_033` de-worked-around (`new Outer::Inner::Gadget` directly); new
`module_034_chained_class_ref` covers new/instanceof at depth 2–3, `extends`, param/return
type hints, single- and multi-segment `module::` self-refs, internal enforcement through the
chain, and the `new $x::Foo` guard. Full modules suite (36) + `new_without_parentheses` (12)
green; 661 namespace/type/class regression tests green.

**Gaps resolved:** the first bullet above (3+ segment class-reference form) is now done.
Remaining Feature-2/3 gaps unchanged: `use Module::Member` and `internal module` enforcement.
Nested cross-file module autoload (tier-1/tier-2 still key off the first `::`) is deferred to
the membership/loading work (Feature 4).

---

## Increment: `internal module` enforcement (Feature 3)

**Goal:** an `internal` nested module is a member of its enclosing module with internal
visibility — usable anywhere inside the parent (including sibling members and deeper
sub-modules), but hidden from outside the parent, including all of its *public* members.

**Declaration (`zend_compile.c`):** `zend_compile_module` captures the transient
`FC(current_member_internal)` signal at entry (the parent set it before dispatching the
nested-module decl), clears it so it does not leak into the nested module's own member
compilation, and re-applies it only when compiling the nested module's **backing class** —
so an internal nested module's backing class is stamped `ZEND_ACC2_MODULE_INTERNAL`. The
class-decl stamp condition no longer excludes `ZEND_ACC_MODULE`, since the signal is now set
precisely (a top-level module's backing class is compiled with it cleared).

**Two distinct predicates (scope membership is NOT transitive).** A member's own `internal`
visibility and an internal *module*'s visibility are different questions and must not be
conflated (an early attempt used a single "containment" rule and was wrong — code in
`Outer::Inner` is in module `Outer::Inner`, not `Outer`, so it must not thereby see `Outer`'s
other internals):

- `zend_module_scope_allows(member_ce, scope)` (`zend_object_handlers.c`) — **strict**: the
  accessor's module must equal the member's own module exactly (backing class → full name for
  the module's own internal statics; member class → name before the last `::`). Reverted to
  strict equality.
- `zend_module_scope_can_see_module(module_ce, scope)` (new, `zend_object_handlers.c`) — true
  iff `scope` is inside the module's own subtree (its module == `M`, or nested under `M::`) OR
  is a **direct** member of `M`'s parent `P` (scope module == `P`). Merely living deeper under
  `P` does not count.

**Runtime gate (`zend_execute_API.c`, `zend_module_runtime_access_denied`):** two cases.
(1) `ce` is itself internal: if it is the backing class of an internal nested module, gate with
`can_see_module`; if it is an internal member class, gate with the strict `scope_allows`.
(2) `ce` lives under an internal nested module — walk the `::` ancestor prefixes and, for any
ancestor that is an internal module, require `can_see_module`. The walk only runs for names
with 2+ `::` (a single-`::` member's only ancestor is a top-level module, which never carries
visibility), so non-nested access pays nothing. This is what hides even the *public* members
(`Outer::Inner::Gadget`, `Outer::Inner::IV`, `Outer::Inner::make()`) of an internal module,
while still letting `Outer`'s direct members and Inner's own subtree in.

**Verified:** from a *direct* member of `Outer` (module code, a sibling member class) the
internal module Inner and its public members are usable; Inner's own code reaches Inner's own
internal members; but Inner reaching UP into `Outer`'s *other* internal members is denied
(non-transitivity — the reachUp probe). From outside `Outer`, const / static-function /
member-class / static-on-member / internal-member access are all denied; public nested modules
remain open; ReflectionModule bypasses; the `ce_flags2` marker survives an opcache file-cache
hit. Tests module_035 (+opcache module_036). 38 module tests green; 611 trait/class/ns +
earlier class/type regressions green.

**Remaining Feature-2/4 gaps unchanged.** Nested cross-file autoload still keys tier-1/tier-2
off the first `::` (deferred to Feature 4); an ancestor module not yet loaded is not gated
(same cross-file caveat).

---

## Increment: `use Module::Member` (Feature 2)

**Goal:** let consuming code import a module member by name, `use Billing::Ledger;` (and
`... as L;`, and chained `use Outer::Inner::Gadget;`), so the short alias resolves to the
canonical `Module::Member`.

**Grammar (`zend_language_parser.y`):** `use_declaration` gains two productions accepting the
existing arbitrary-depth `module_qualified_name` nonterminal (with and without `T_AS`). Because
the module-qualified name is reached only when a `::` follows the leading name — distinct from
the `;`/`,`/`as` that follow a plain `legacy_namespace_name` — bison reports zero conflicts
(`%expect 0` holds). No new AST: the USE_ELEM just carries the concatenated canonical name.

**Default alias (`zend_compile.c`, `zend_get_unqualified_name`):** now splits on the rightmost
of `\` or `::`, so `use Billing::Ledger` aliases `Ledger`, `use Billing::Auth\Checker` aliases
`Checker`, and `use Outer::Inner::Gadget` aliases `Gadget`. The rest of `zend_compile_use` is
unchanged: the alias→canonical entry lands in the class import table, and later bareword
resolution expands the alias to `Billing::Ledger`, which flows through the normal module
boundary / autoload / internal-gate machinery.

**Pure aliasing.** `use` on an `internal` member is allowed and is not itself an access; the
boundary is enforced only when the imported name is actually used from outside the module
(runtime), consistent with the uniform-runtime rule. Verified in module_037.

**Verified:** default and explicit aliases; namespaced module name (`VendorName\User::Profile`);
chained nested import; alias in type hints and `instanceof`; internal import allowed but access
denied. Test module_037. 39 module + 151 module/namespace regressions green.
