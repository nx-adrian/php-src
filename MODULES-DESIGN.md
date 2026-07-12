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
