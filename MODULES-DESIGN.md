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
