# SugarCraft → PHP 7.4 Backport Plan (`php7.4` branch)

> **Status: PLAN ONLY — do not start execution.**
> This document is the complete, agent-executable plan for producing a fully PHP 7.4‑compatible
> line of the SugarCraft monorepo on a dedicated long‑lived branch, while `master` continues to
> require PHP 8.2/8.3+. It is derived from a full automated audit of all 56 libraries
> (1,361 source files, 987 test files) performed 2026‑06‑01.

---

## 0. Executive summary

A PHP 7.4 backport of SugarCraft is **a full source rewrite, not a dependency bump.** The
external dependency set is tractable (every runtime package has a 7.4‑capable release line), but
the *source* is saturated with PHP 8.0/8.1/8.2 **syntax** that 7.4 cannot parse and that no
Composer polyfill can fix:

| Construct | PHP | Libs affected | Occurrences | Backport nature |
|---|---|---:|---:|---|
| `readonly` property | 8.1 | 52 | 2,862 | Mechanical (strip keyword + `@readonly`) |
| Constructor promotion | 8.0 | 52 | 907 | Mechanical (expand to explicit props) |
| `match` expression | 8.0 | 43 | 414 | **Semantic** (→ `switch`, strict‑compare care) |
| Named arguments (mostly tests) | 8.0 | 35 | 2,451 | Mechanical but high‑volume (→ positional) |
| `str_contains`/`_starts_with`/`_ends_with` | 8.0 | 33 | 196 | **Solved by polyfill** (no code change) |
| Nullsafe `?->` | 8.0 | 26 | 98 | Mechanical (temp‑var null check) |
| `readonly class` | 8.2 | 24 | 109 | Mechanical (strip modifier) |
| `mixed` type | 8.0 | 23 | 47 | Mechanical (drop hint + docblock) |
| Union types `A\|B` | 8.0 | 22 | 110 | Mechanical (`?T` native; else drop+docblock) |
| **Enum with methods/interface** | 8.1 | 19 | 44 | **Hard** (class‑based polyfill) |
| Backed enum (plain) | 8.1 | 16 | 78 | Hard‑ish (class‑based polyfill) |
| `new` in initializer | 8.1 | 13 | 16 | Mechanical (null default + body init) |
| `throw` expression | 8.0 | 13 | 20 | Mechanical (→ statement) |
| `static` return type | 8.0 | 12 | 60 | Mechanical (`self`/docblock) |
| Non‑capturing `catch` | 8.0 | 12 | 14 | Trivial (add `$e`) |
| First‑class callable `f(...)` | 8.1 | 11 | 32 | Mechanical (`Closure::fromCallable`) |
| Attributes `#[...]` (src) | 8.0 | 9 | 32 | Case‑by‑case |
| `never` type | 8.1 | 5 | 1 | Trivial (drop + docblock) |
| Intersection types `A&B` | 8.1 | 3 | few | Trivial (drop + docblock) |
| `null`/`false`/`true` standalone type | 8.2 | 3 | few | Trivial |
| **Fibers** | 8.1 | 2 | — | **Hard / redesign** (candy‑ansi, candy‑testing) |
| `WeakMap` | 8.0 | 1 | 3 | Mechanical (`SplObjectStorage`) |

Already‑7.4 features in the codebase (arrow `fn`, `??`, `??=`, spread, typed props, FFI) need **no
change**. FFI landed in 7.4, so `candy-pty` / `candy-core` Windows console code is not blocked at
the language level.

**Effort shape:** ~80% of the work is mechanical and parallelizable per file; the irreducible
design work is (a) the **enum polyfill** (35 libs touch enums), (b) **`match`→`switch`** done
without changing comparison semantics, and (c) **fibers** in `candy-ansi`/`candy-testing`.

---

## 1. Goals, non‑goals, branch model

### Goals
1. A `php7.4` branch where every lib's `composer.json` declares `"php": "^7.4 || ^8.0"`, every
   `phpunit.xml` targets PHPUnit 9.6, and **every lib's test suite is green under a real PHP 7.4
   interpreter**.
2. Identical public API and runtime behaviour to `master` (same class names, method signatures
   modulo type‑system erasure, same rendered output). Snapshot/golden tests must still pass.
3. `master` is never modified by this effort and keeps its 8.2/8.3+ requirement.

### Non‑goals
- No feature changes, refactors, or "while we're here" cleanups. Pure backport.
- No attempt to keep `master` and `php7.4` mergeable; they diverge permanently.

### Branch model (explicit)
- **Integration branch:** `php7.4`, cut once from `master`. This is the trunk for the whole
  effort — every step merges into `php7.4`. The real `master` is **never** checked out for writes
  during this effort.
- **Per‑step branch:** `php7.4/<phase>-<lib>[-<chunk>]` (e.g. `php7.4/p2-candy-core-msg-1`).
- **Every step is exactly one commit → one PR → merged into `php7.4`.** PR **base branch is
  `php7.4`**, not `master`.
- Author on every commit: `Joe Huss <detain@interserver.net>`.

> ✅ **Confirmed:** every step's PR merges into the **`php7.4` integration branch** — that is the
> "local trunk" for this effort. The real `master` is left untouched (it must stay 8.x). "Merge
> back into local master" meant this `php7.4` branch, **not** the actual `master`.

---

## 2. Orchestration model (carried down at every level)

```
Master agent
└─ spawns Phase agent (one at a time, serially, in phase order)
   └─ spawns Coder agent (one per step, serially, in step order)
      └─ Step substep pipeline (each substep is its OWN agent, serial):
         1. Coder      — implement the step
         2. Reviewer   — review diff vs. the step's stated expectation
         3. Fixer      — apply fixes for reviewer findings
            ↺ Reviewer ⇄ Fixer repeat until Reviewer reports ZERO problems
         4. Tester     — update / fix / build out tests; run them green
         5. Documentor — update docs/site/README/CALIBER_LEARNINGS/PHPDoc blocks
         6. Ship       — commit → PR → merge into php7.4 (the integration trunk)
```

**Hard rules threaded to every spawned agent (verbatim block — every agent passes it down):**

> - **Spawn serially.** Never run two child agents concurrently. Wait for each to finish.
> - **Keep context minimal.** A coder/reviewer/fixer/tester/documentor agent is given ONLY: this
>   instruction block, the Rulebook (§4), the step spec (file list + expectation), and the files
>   it must touch. It must not read the whole monorepo.
> - **`unset GITHUB_TOKEN` before every `gh` invocation.** Always `unset GITHUB_TOKEN && gh …`.
> - **One step = one commit = one PR**, base branch `php7.4`, author `Joe Huss
>   <detain@interserver.net>`.
> - **Never touch `master`.** Never touch files outside the step's declared scope.
> - **Skip Caliber** on this machine (do not run `caliber refresh`; if a hook stages
>   Caliber‑managed files, unstage them before committing).
> - **Definition of done is the §3 checklist.** A step is not shippable until tests are green
>   under PHP 7.4 for that lib *and all its already‑backported dependencies*.

### 2.1 Agent contracts (what each substep agent receives and returns)

| Agent | Input | Must do | Returns |
|---|---|---|---|
| **Master** | This plan | Cut `php7.4`; run Phase 0; then spawn Phase agents in order; track `plans/PHP74_BACKPORT.md` checkboxes | Per‑phase status |
| **Phase agent** | Phase spec (ordered step list) | Spawn Coder per step serially; subdivide any oversized step using the §3.1 sizing rule; do not advance to next step until current step's PR is merged | Per‑step status |
| **Coder** | Step spec + Rulebook | Apply Rulebook transforms to exactly the listed files; run that lib's phpunit locally; do **not** commit | Diff summary + which Rulebook rules applied |
| **Reviewer** | The diff + step expectation + Rulebook | Verify: every 8+ construct in scope removed; behaviour preserved; no out‑of‑scope edits; `match`→`switch` strictness correct; enum polyfill faithful | `APPROVED` or a findings list (file:line) |
| **Fixer** | Reviewer findings | Fix only the findings; re‑run phpunit | Updated diff |
| **Tester** | Backported lib | Convert tests to PHPUnit 9 (attributes→annotations, `phpunit.xml` 10.5→9.5); add tests for any new polyfill class; run `vendor/bin/phpunit` green on PHP 7.4 | Test result |
| **Documentor** | Backported lib | Update README badges/version notes, `CALIBER_LEARNINGS.md`, `docs/lib/<slug>.html`, PHPDoc blocks (`@readonly`, `@return static/mixed/never`), MATCHUPS row if status changes | Docs diff |
| **Ship** | Approved+tested+documented tree | `git checkout -b php7.4/<…>`; commit; push; `unset GITHUB_TOKEN && gh pr create --base php7.4 …`; `gh pr merge <n> --merge --delete-branch`; `git checkout php7.4 && git pull --ff-only` | PR # + merge confirmation |

### 2.2 Reviewer⇄Fixer loop termination
- Loop runs until Reviewer returns `APPROVED` with zero findings, **max 5 rounds**. If still
  failing at round 5, the Phase agent escalates to the Master agent (do not ship a red step).

---

## 3. Definition of done (per step)

A step's Ship substep may run only when **all** are true:
- [ ] Every in‑scope PHP 8+ construct (per the step's feature list) is gone; `php -l` under 7.4
      parses every touched file.
- [ ] No public API change (class/method names + parameter order identical; types may be erased
      to docblocks per Rulebook).
- [ ] `cd <lib> && composer update && vendor/bin/phpunit` is **green on PHP 7.4** (with all
      already‑backported sugarcraft path‑repo deps present).
- [ ] `phpunit.xml` targets schema 9.5; tests use annotations not attributes.
- [ ] `composer.json` constraints updated (§5); `php tools/check-path-repos.php` passes.
- [ ] Reviewer returned `APPROVED`.
- [ ] Docs/PHPDoc/CALIBER_LEARNINGS updated.
- [ ] One commit, one merged PR into `php7.4`.
- [ ] `plans/PHP74_BACKPORT.md` checkbox ticked.

### 3.1 Step sizing rule (so phase agents subdivide big libs consistently)
- **Target ≤ ~10 source files OR one cohesive sub‑namespace per step**, whichever is smaller, so
  one coder + one reviewer can hold the whole diff in a small context.
- A lib with ≤ ~12 src files = **one step**.
- A larger lib is split **by `src/` subdirectory** (see §6 enumerations). A subdirectory with
  >12 files is split again by file alphabetically into `-1`, `-2` chunks.
- The lib's **composer.json + phpunit.xml + shared root files** are done in that lib's **first**
  step; subsequent steps of the same lib only touch source/tests.

---

## 4. The Rulebook — canonical PHP 8+ → 7.4 transforms

Every Coder agent applies these and only these. Each is mechanical unless marked **DESIGN**.

### 4.1 Constructor property promotion (8.0)
```php
// FROM
public function __construct(private readonly int $x, public string $y = 'a') {}
// TO
/** @readonly */ private int $x;
public string $y;
public function __construct(int $x, string $y = 'a') { $this->x = $x; $this->y = $y; }
```

### 4.2 `readonly` property (8.1) & `readonly class` (8.2)
- Strip the `readonly` keyword from properties; add `/** @readonly */` PHPDoc.
- `final readonly class X` → `final class X` (then expand its promoted constructor).
- **Semantic note:** 7.4 cannot enforce immutability at runtime. The codebase is already
  immutable‑by‑convention (`with*()` returns a new instance via `mutate()`), so this is a
  documented, accepted erosion — **no runtime guards** (avoids 2,862 sites of churn + perf cost).

### 4.3 Enums (8.1) — **DESIGN**, the central artifact
A class‑based polyfill ships in **candy‑core** as `SugarCraft\Core\Polyfill\BackedEnum` and
`...\UnitEnum`. Each native enum becomes a `final class` extending the polyfill. The polyfill
must support everything the codebase uses: `->value`, `->name`, `::cases()`, `::from()`,
`::tryFrom()`, instance identity (singletons so `===` works), and use as a parameter type.

```php
// FROM  (candy-vt/src/Sgr/…, candy-ansi/src/Parser/Action.php, etc.)
enum Action: int {
    case Print = 0;
    case Execute = 1;
    public function label(): string { return match($this) { self::Print => 'print', self::Execute => 'exec' }; }
}
// TO
final class Action extends \SugarCraft\Core\Polyfill\BackedEnum {
    public static function Print(): self  { return self::of('Print', 0); }
    public static function Execute(): self { return self::of('Execute', 1); }
    public function label(): string {
        switch ($this->value) { case 0: return 'print'; case 1: return 'exec'; }
        throw new \LogicException();
    }
}
```
- `BackedEnum::of($name,$value)` returns a per‑name singleton (identity‑safe).
- **Call‑site rewrite:** `Action::Print` → `Action::Print()`. This is the one ripple that escapes
  a lib boundary — a producer enum's consumers must switch to the `()` form. The §6 ordering
  guarantees the enum's lib is backported before its consumers, so consumer steps pick up `()`.
- Pure (non‑backed) enums extend `UnitEnum` (no `value`).
- `match($enum)` over cases → `switch` on `$enum->name` (or identity compare).
- ⚠️ **`::cases()` ordering and `from()` exhaustiveness** must be preserved; the Tester adds a
  test per converted enum asserting `cases()` order, `from`/`tryFrom`, and `===` identity.

> Alternative considered: `myclabs/php-enum`. Rejected — it is unmaintained for new work and its
> API (`::VALUE()`) differs enough that a thin in‑house base gives a closer faithful mapping and
> zero new external runtime dep.

### 4.4 `match` expression (8.0) → `switch` — **care**
- `match` uses **strict** (`===`) comparison and throws `\UnhandledMatchError` on no match;
  `switch` uses **loose** (`==`). Rewrite preserving strictness:
  - Statement assignment `$r = match($v) { 1,2 => 'a', default => 'b' };` →
    ```php
    switch (true) {
        case $v === 1: case $v === 2: $r = 'a'; break;
        default: $r = 'b';
    }
    ```
    (Use `switch (true)` + `case $v === X` to keep strictness; or `switch ($v)` only when `$v` is
    an `int`/`string` literal scalar where loose==strict is provably safe.)
  - `match` **without** a `default` arm → add `default: throw new \UnhandledMatchError();` (or
    `\LogicException` if `UnhandledMatchError` is unavailable on 7.4 — it is 8.0, so define a
    small shim in candy‑core: `SugarCraft\Core\Polyfill\UnhandledMatchError`).
  - `match` used as a sub‑expression (argument, return) → extract to a local `$tmp` via `switch`
    immediately above, or to a `private` method.

### 4.5 Named arguments (8.0) → positional
- Reorder to positional, supplying any skipped default values explicitly. Most occurrences are in
  **tests** (e.g. `retry(attempts: 3, baseBackoffSeconds: 0.01)`); convert there too.

### 4.6 Nullsafe `?->` (8.0)
```php
$x?->y()?->z   →   (($t = $x) !== null ? (($u = $t->y()) !== null ? $u->z : null) : null)
```
Prefer an early‑return/temp‑var rewrite for readability over nested ternaries when in statement
position.

### 4.7 PHP 8.0 stdlib functions — **add polyfill, do NOT rewrite code**
`str_contains`, `str_starts_with`, `str_ends_with`, `get_debug_type`, `Stringable` are provided by
**`symfony/polyfill-php80`**. Add it as a `require` in **candy‑core** (transitively covers the 52
libs depending on core) plus to any leaf lib that uses them without a core dep. No source edits.
8.1 stdlib (`array_is_list`, `enum_exists`) → `symfony/polyfill-php81` if needed.

### 4.8 Type‑system erasure
- `mixed` → remove hint, add `@param mixed`/`@return mixed`.
- Union `A|B` → if it is `X|null`, use native `?X`; otherwise drop hint + docblock `@param A|B`.
- `static` return → `self` when the class is `final` (most are); for inheritable fluent bases drop
  native type + `@return static`.
- `never` → drop type (+`@return never` / `@phpstan-return never`).
- Intersection `A&B` → drop hint + `@param A&B`.
- `null`/`false`/`true` standalone (8.2) → `null`→`?T`/none; `false`/`true`→`bool` + docblock.

### 4.9 Other syntax
- `new` in initializer (default param `= new X()`) → default `null`, body `$p = $p ?? new X();`.
- `throw` expression → expand to an `if (...) { throw ...; }` statement.
- Non‑capturing `catch (E)` → `catch (E $e)` (unused).
- First‑class callable `f(...)` / `$o->m(...)` → `\Closure::fromCallable('f')` /
  `\Closure::fromCallable([$o, 'm'])`.
- `WeakMap` (candy‑flip) → `\SplObjectStorage` (audit confirms map‑style use; preserve semantics).
- Array spread with string keys (8.1) → `array_merge(...)`.
- Attributes `#[...]` in **src** (candy‑shell, candy‑vcr, candy‑tetris, candy‑testing, sugar‑glow,
  sugar‑crush, candy‑boxer/sugar‑boxer, candy‑ansi, candy‑shine) → evaluate per case: if they are
  a framework's own metadata read via Reflection, convert to a 7.4 mechanism (docblock parse, a
  static registry, or an interface method); if PHPUnit, see §4.10.

### 4.10 Tests / PHPUnit (8.1‑only tool → 9.6)
- PHPUnit 10/11 need PHP 8.1; **7.4 caps at PHPUnit 9.6.**
- `composer.json`: `phpunit/phpunit: ^10.x` → `^9.6`.
- `phpunit.xml`: schema `10.5` → `9.5`; revert `<source><include>` → `<coverage><include>`;
  keep `bootstrap`, `colors`, `failOnWarning`, `cacheDirectory` (9.3+).
- Test attributes `#[DataProvider]`/`#[Group]`/`#[CoversClass]` → `@dataProvider`/`@group`/
  `@covers` docblocks. (Blast radius is tiny — repo overwhelmingly uses `test*`+docblock already.)
- `void` test return types are fine in 9.x.

### 4.11 **Fibers (candy‑ansi, candy‑testing)** — **DESIGN / RISK**
7.4 has no Fibers. The Coder agent must first *read the actual usage*:
- If fibers back a cooperative scheduler/coroutine in `candy-testing`'s program simulator, replace
  with a generator‑based driver or a synchronous step‑pump (ReactPHP loop already available).
- If `candy-ansi`'s fiber use is a feature‑detected fast path with an existing fallback, gate it
  behind `if (\PHP_VERSION_ID >= 80100 && class_exists(\Fiber::class))` and ship the fallback for
  7.4.
- These two steps get **mandatory extra review rounds** and their own risk note in the PR body.

---

## 5. Cross‑cutting dependency & tooling changes

Folded into the relevant lib's first step (composer changes) or Phase 0 (global tooling).

### External runtime deps (per the audit)
| Package | master | php7.4 | Note |
|---|---|---|---|
| `react/event-loop` | `^1.6` | `^1.6` | 1.x supports 7.1+ — no change |
| `react/promise` | `^3.3` | `^3.3` (fallback `^2.11`) | 3.x min is 7.1; keep, pin down only if resolution fails |
| `react/promise-timer` | `^1.9` | `^1.9` | no change |
| `psr/log` | `^3.0` | `^1.1` | 3.x needs PHP 8; **drop param types** in any `LoggerInterface` impl (candy‑log) |
| `symfony/console` | `^6.4 \|\| ^7.0` | `^5.4` | 6.4 needs 8.1 (candy‑shell, candy‑vcr, sugar‑glow) |
| `symfony/process` | `^6.4 \|\| ^7.0` | `^5.4` | candy‑vcr |
| `symfony/yaml` (dev) | `^6.4 \|\| ^7.0` | `^5.4` | candy‑vcr |
| `league/commonmark` | `^2.4` | `~2.4.0` | 2.5+ bumped to PHP 8.1 (candy‑shine) |
| `phpunit/phpunit` (dev) | `^10.x` | `^9.6` | all libs |
| `phpstan/phpstan` (dev) | `^2.1` | `^2.1` | runs on 7.4 host — keep |
| `friendsofphp/php-cs-fixer` (dev) | `^3.65` | `^3.65` | keep; set `'php_version' => 70400` |
| `symfony/polyfill-php80` | — | **add** | new: str_* funcs, get_debug_type, Stringable |
| `symfony/polyfill-php81` | — | add if needed | array_is_list/enum_exists |

`ext-*` requirements (`ext-ffi`, `ext-gd`, `ext-mbstring`, `ext-pdo_sqlite`, `ext-sqlite3`,
`ext-pcntl`, …) all exist on 7.4 — no change.

### Per‑lib `composer.json` `php` constraint
`"php": "^8.3"` / `">=8.3"` / `"^8.1"` → **`"php": "^7.4 || ^8.0"`** (keeps the branch installable
on both, so CI can prove 7.4 *and* 8.x parse the backported source).

### Tooling (Phase 0, on the `php7.4` branch only)
- `scripts/affected-libs.php`: `PHP_VERSIONS`, `WINDOWS_PHP_VERSIONS`, `MACOS_PHP_VERSIONS`,
  `COVERAGE_PHP_VERSION` → add/switch to `'7.4'`. The script itself uses `str_starts_with`
  (8.0) — replace with `strncmp(...) === 0` so the orchestration script runs under 7.4.
- `.github/workflows/ci.yml`, `pty-matrix.yml`, `vhs.yml`, `vhs-smoke-test.yml`: change pinned
  `php-version: '8.3'`/`'8.4'` cells to include `'7.4'` for the test matrix (VHS/visual jobs can
  stay 8.x — they only render demos).
- `.php-cs-fixer.dist.php`: add `'php_version' => 70400` awareness / run under a 7.4 interpreter so
  it never re‑introduces 8‑only constructs.
- Keep hard‑coded SVN creds in `tests.yml` (per repo gotcha).

---

## 6. Phases & steps (dependency‑ordered)

Order is the topological dependency order from the audit, so each lib is backported only after its
sugarcraft deps are already 7.4‑clean (required because path‑repo symlinks expose dependency
*source* to phpunit — 7.4 cannot even parse an un‑backported dep). `candy-core ↔ candy-pty` is a
dependency cycle and is backported as one fused phase.

### Phase 0 — Branch & global infrastructure
- **0.1** Cut `php7.4` from `master`; add `plans/PHP74_BACKPORT.md` (this checklist); add a
  `BACKPORT.md`/CONTRIBUTING note documenting the branch policy.
- **0.2** Tooling: `scripts/affected-libs.php`, CI matrices, `.php-cs-fixer.dist.php` (§5).
- **0.3** Root `composer.json`: constraint policy, add polyfill repos if vendored.
- *(No lib source yet. The enum/`UnhandledMatchError` polyfills land in Phase 1's first
  candy‑core step because they must be written in candy‑core itself.)*

### Phase 1 — Leaf foundation (topo level 0, no sugarcraft deps)
Each is one step unless noted. `candy-ansi`, `candy-async`, `candy-buffer` are deps of the
keystone, so they come first within the phase.
- **1.1** `candy-async` (8 src, medium) — react deps; `readonly class Suspended`, union types.
- **1.2** `candy-ansi` (11 src, low) — 2 backed enums (→ polyfill), `match`, `??=`. *(First enum
  conversions — establishes the §4.3 pattern; extra review.)*
- **1.3** `candy-buffer` (17 src, medium) — split into **1.3a** root+`Diff/` and **1.3b**
  `Cell/Style/Position/Hyperlink/...`; heavy `readonly`+promotion, 8 nullsafe.
- **1.4** `candy-fuzzy` (6 src, low).
- **1.5** `candy-layout` (13 src, low) — 1 enum, `readonly class`.
- **1.6** `candy-input` (11 src, high) — union types, `readonly class`, mixed; no deps so safe early.

### Phase 2 — Keystone: candy‑core (+ candy‑pty, cyclic)
`candy-core` (118 src, high) split by subdir; **2.1 must ship the polyfills first.**
- **2.1** candy‑core **polyfills + composer/phpunit**: `Polyfill/BackedEnum`, `Polyfill/UnitEnum`,
  `Polyfill/UnhandledMatchError`; add `symfony/polyfill-php80`; constraint+phpunit.xml. *(Written
  natively in 7.4 syntax; has its own new tests.)*
- **2.2** core `Msg/` (38 files) → **2.2a/2.2b/2.2c/2.2d** (~10 files each, alphabetical).
- **2.3** core `Util/` (12) + **2.4** core `Util/Tty/` (7, incl. Windows FFI `Kernel32` enum).
- **2.5** core `Exception/` (6) + `Cmd/` (4) + `Undo`,`Progress`,`I18n` (2 each).
- **2.6** core root files (`View`, `Subscriptions*`, `Screen*`, `WorkerPool`, `SgrState`, …) →
  **2.6a/2.6b** (~10 each).
- **2.7** `candy-pty` (42 src, high) → **2.7a** `Posix/` (11, FFI/libc/termios), **2.7b**
  `Contract/` (8), **2.7c** `Exception/`+`Output/` (7), **2.7d** root files (`Pty`,`Master`,
  `Spawn`,`Libc`,`SizeIoctl`,…). FFI is 7.4‑OK; focus is syntax only.

### Phase 3 — Core‑only & low‑fan‑out foundation
Ordered so each lib's deps are already done. One step each unless split noted.
- **3.1** `candy-layout`‑dependent + core‑only singles: `candy-metrics`(13,low),
  `candy-zone`(14,low), `candy-mouse`(9,med), `candy-mold`(1,low).
- **3.2** `candy-sprinkles` (43, med) — deps core+layout → **3.2a** `Layout/` (12), **3.2b**
  `Table/Border/Tree/Listing` (10), **3.2c** root color/style files (≈10), **3.2d** remainder.
- **3.3** `candy-palette`(8,med), `candy-kit`(7,med), `candy-lister`(10,med) — deps core(+sprinkles).
- **3.4** `candy-testing`(8,med) — deps core+buffer; **Fiber** + attributes + first‑class‑callable
  + intersection. *(High‑touch; extra review per §4.11.)*
- **3.5** `candy-serve`(14,med, deps core+async), `candy-query`(14,high, core+sprinkles),
  `candy-log`(16,high, core+palette+sprinkles; psr/log→^1.1 + drop param types).
- **3.6** `candy-mosaic`(25,high, core+palette+sprinkles) → **3.6a** `Renderer/` (7), **3.6b**
  root (≈10), **3.6c** remainder; ext‑gd/mbstring.
- **3.7** `candy-shine`(9,high, buffer+core+sprinkles; attributes, league/commonmark pin).
- **3.8** `candy-vt`(40,high, buffer+core+sprinkles) → **3.8a** `Parser/` (11), **3.8b**
  `Handler/` (8), **3.8c** `Sgr/Screen/Msg/...`, **3.8d** root (`Terminal`,`Cell*`,`Cursor*`,…).
- **3.9** `candy-vcr`(81,med, buffer+core+pty; symfony console/process/yaml→^5.4) → **3.9a**
  `Tape/Ast` (19→ split a/b), **3.9b** `Cli` (10), **3.9c** `Format/Raster/Assert` (17), **3.9d**
  `Tape/Msg/Migration/Matcher/Hook/Encode/Render` (≈24 → split), **3.9e** root files.
- **3.10** `candy-wish`(35,med, core+pty) → **3.10a** `Middleware/*` (14), **3.10b** `Channel/*`
  (11), **3.10c** `Transport/*`+root (≈10).
- **3.11** `candy-mines`(9,high, buffer+core+mouse+sprinkles), `candy-flip`(9,med,
  core+pty+sprinkles; **WeakMap→SplObjectStorage**).

### Phase 4 — Components
- **4.1** `candy-forms`(48,med, async+buffer+core+fuzzy+layout+sprinkles) → **4.1a** `Field/` (8),
  **4.1b** `Validator/` (6), **4.1c** `TextInput/Spinner/ItemList/FilePicker/Cursor` (≈16 → a/b),
  **4.1d** `Vim/Viewport/TextArea/Scrollbar`+root (≈18 → a/b).
- **4.2** `candy-hermit`(7,med, ansi+pty+sprinkles), `candy-freeze`(10,high,
  ansi+buffer+core+shine+sprinkles).
- **4.3** `honey-bounce`(12,high, core+palette; enum‑with‑methods), then `honey-flap`(6,low,
  core+sprinkles+honey‑bounce).
- **4.4** `candy-shell`(41,med, core+forms+fuzzy+pty+shine+sprinkles; symfony console→^5.4,
  src attributes) → **4.4a** `Command/` (13→a/b), **4.4b** `Model/` (8), **4.4c** `Attribute/`
  (5, the attribute→7.4 mechanism), **4.4d** `Process/Completion/Style/Help/...`+root.
- **4.5** `candy-tetris`(14,med, buffer+core+mouse+sprinkles; attributes, first‑class‑callable,
  enum‑with‑methods).

### Phase 5 — Apps & top of the graph
- **5.1** `sugar-bits`(49,high, many deps) → **5.1a** `Table` (5), **5.1b** `TextInput/Timer/
  Progress` (12), **5.1c** `Spinner/Key/ItemList/FilePicker/Cursor` (≈15→a/b), **5.1d**
  `Viewport/Tree/TextArea/Tabs/Stopwatch/Scrollbar/Paginator/Help`+root (≈17→a/b).
- **5.2** `sugar-boxer`(3,low), `sugar-calendar`(6,low), `sugar-crumbs`(8,low),
  `sugar-skate`(8,med), `sugar-spark`(8,med), `sugar-stickers`(7,med), `sugar-wishlist`(6,med),
  `sugar-post`(7,high), `sugar-stash`(14,high), `sugar-table`(8,high), `sugar-toast`(9,high),
  `sugar-veil`(7,high) — one step each (all small), in dependency order.
- **5.3** `sugar-prompt`(23,low, deps incl. sugar‑bits) → **5.3a** `Field/` (8), **5.3b**
  `Validator/` (6), **5.3c** root.
- **5.4** `sugar-glow`(10,med, core+shine+sugar‑bits), `sugar-readline`(17,med, forms+input →
  a/b), `sugar-crush`(20,high, buffer+core+shine+sprinkles; attributes, MCP) → a/b.
- **5.5** `sugar-charts`(28,med, buffer+core+sprinkles+**sugar‑dash**) → **5.5a** `LineChart/
  Canvas/Picture` (10), **5.5b** `Aggregation/OHLC/Heatmap/Chart/BarChart` (≈11), **5.5c**
  remainder. *(Note: charts depends on dash → dash must precede.)*
- **5.6** `sugar-dash` (**345 src, high — the largest single effort**, deps buffer+core+pty+
  sprinkles). Split by subtree into ~20 steps, each ≤ ~12 files:
  `Components/Card` (36→4 steps), `Plot/Chart` (35→3), `Components/Tree` (31→3),
  `Layout`+`Layout/Tile`+`Layout/Boxer` (46→4), `Components/GridTable` (17→2),
  `Components/Media` (15→2), `Components/System` (13), `Foundation` (12),
  `Components/Modal`+`Modal/Msg` (17→2), `Components/Form` (10), `Events` (9),
  `Components/Select` (9), `Components/Nav` (9), `Module` (8), `Keys` (7),
  `Components/Toast` (7), `Plugin` (5) + remaining root files.
- **5.7** `sugar-tick`(16,high, async+core+sprinkles+sugar‑charts), `sugar-reel`(19,high, many
  deps incl. mosaic/flip). ⚠️ **`sugar-reel` is in active development** (working‑tree changes to
  `Player.php`/`AudioPlayer.php` at audit time) — schedule **last**, re‑audit the lib immediately
  before its step, and coordinate so the backport rebases on the finished feature.
- **5.8** `super-candy`(14,med, core+mosaic+sprinkles).

### Phase 6 — Finalisation
- **6.1** Root `composer.json` closure + `php tools/check-path-repos.php --fix`; full
  `for d in <all>; do (cd $d && composer update && vendor/bin/phpunit); done` green on a real 7.4
  interpreter.
- **6.2** CI: confirm the 7.4 matrix is green on the `php7.4` branch (matrix push, not PR — per
  the repo's force‑all gotcha).
- **6.3** Docs site (`docs/index.html`, `docs/lib/*.html`), README badges, `MATCHUPS.md` notes
  for the 7.4 line; final `plans/PHP74_BACKPORT.md` sign‑off.

**Approx step count:** ~140–160 steps (each a small, single‑lib‑or‑subdir commit/PR).

---

## 7. Risk register

| Risk | Where | Mitigation |
|---|---|---|
| **Fibers** with no clean 7.4 equivalent | candy‑ansi, candy‑testing | §4.11 — read usage first; generator/loop fallback or version‑gated path; extra review |
| **`match`→`switch` strictness drift** (`==` vs `===`) | 43 libs | `switch (true)`+`case $v === X`; Reviewer explicitly checks each |
| **Enum identity / `cases()` order / `from()`** | 35 libs | candy‑core `BackedEnum` singletons; Tester adds identity+order+from tests per enum |
| **Enum `::Case` → `::Case()` ripple across lib boundaries** | enum producers→consumers | Strict topo order; consumer steps update call sites; CI catches misses |
| **psr/log 1.1 typed‑signature mismatch** | candy‑log | Drop param types from LoggerInterface impls |
| **Symfony 5.4 API gaps vs 6.x** | candy‑shell, candy‑vcr, sugar‑glow | Pin `^5.4`; Tester runs console/process paths; adjust any 6‑only call |
| **league/commonmark 2.5 auto‑upgrade to PHP 8.1** | candy‑shine | Pin `~2.4.0` |
| **sugar‑dash size (345 files)** | Phase 5.6 | ~20 small subtree steps; longest pole — start its phase early in parallel‑safe ordering once deps done |
| **In‑progress work** | sugar‑reel (+ any other active libs) | Backport **last**; re‑audit immediately before; never touch a lib while the user is mid‑edit |
| **Runtime immutability lost** (no `readonly` on 7.4) | 52 libs | Accepted; `@readonly` PHPDoc + existing `with*()` convention; PHPStan can enforce statically |
| **Path‑repo parse failures** if a dep isn't backported yet | all | Enforced topo order; `composer update` per step proves dep set parses on 7.4 |

---

## 8. Appendix A — Per‑lib audit matrix

`diff` = backport difficulty; `src` = source file count; features list is representative.

| Lib | diff | src | Key PHP 8+ constructs present |
|---|---|---:|---|
| candy-core | high | 118 | backed-enum, enum-with-methods, ctor-promotion, match, mixed, named-args, readonly |
| candy-freeze | high | 10 | ctor-promotion, enum, match, mixed, new-in-init, php8-strfn |
| candy-input | high | 11 | ctor-promotion, mixed, php8-strfn, readonly-class, readonly, union |
| candy-log | high | 16 | backed-enum, enum-with-methods, ctor-promotion, match, named-args, readonly; psr/log |
| candy-mines | high | 9 | ctor-promotion, enum-with-methods, match, named-args, readonly |
| candy-mosaic | high | 25 | backed-enum, enum-with-methods, ctor-promotion, match, named-args, php8-strfn; gd/mbstring |
| candy-pty | high | 42 | FFI, ctor-promotion, match, mixed, new-in-init, nullsafe |
| candy-query | high | 14 | enum-with-methods, ctor-promotion, match, mixed, named-args; pdo_sqlite |
| candy-shine | high | 9 | attribute, ctor-promotion, enum, match, named-args, nullsafe; commonmark |
| candy-vt | high | 40 | backed-enum, enum-with-methods, ctor-promotion, match, mixed, named-args |
| honey-bounce | high | 12 | enum-with-methods, ctor-promotion, match, named-args, readonly |
| sugar-bits | high | 49 | enum, enum-with-methods, ctor-promotion, match, mixed, named-args, new-in-init |
| sugar-crush | high | 20 | attribute, enum (backed+pure), ctor-promotion, match, named-args, non-capturing-catch |
| sugar-dash | high | 345 | backed-enum, enum-with-methods, ctor-promotion, match, mixed, named-args (everything) |
| sugar-post | high | 7 | ctor-promotion, mixed, named-args, php8-strfn, readonly |
| sugar-reel | high | 19 | backed-enum, enum-with-methods, ctor-promotion, first-class-callable, match — **in progress** |
| sugar-stash | high | 14 | backed-enum, enum-with-methods, ctor-promotion, match, named-args, new-in-init |
| sugar-table | high | 8 | enum, match, mixed, named-args, nullsafe, php8-strfn, readonly |
| sugar-tick | high | 16 | ctor-promotion, first-class-callable, intdiv, named-args, new-in-init |
| sugar-toast | high | 9 | backed-enum, enum, enum-with-methods, ctor-promotion, match, readonly |
| sugar-veil | high | 7 | ctor-promotion, enum, enum-with-methods, match, named-args, readonly |
| candy-async | medium | 8 | ctor-promotion, mixed, named-args, readonly-class, union; react |
| candy-buffer | medium | 17 | ctor-promotion, nullsafe, readonly, union |
| candy-flip | medium | 9 | WeakMap, ctor-promotion, named-args, readonly |
| candy-forms | medium | 48 | backed-enum, ctor-promotion, match, non-capturing-catch, nullsafe |
| candy-hermit | medium | 7 | ctor-promotion, first-class-callable, readonly-class |
| candy-kit | medium | 7 | ctor-promotion, named-args, ??=, readonly, union |
| candy-lister | medium | 10 | enum, match, nullsafe, php8-strfn, readonly |
| candy-mouse | medium | 9 | backed-enum, ctor-promotion, match, nullsafe, php8-strfn |
| candy-palette | medium | 8 | ctor-promotion, enum-with-methods, match, named-args, new-in-init |
| candy-serve | medium | 14 | ctor-promotion, first-class-callable, match, named-args; ssh2/openssl |
| candy-shell | medium | 41 | attribute, backed-enum, ctor-promotion, enum-with-methods, match, mixed; symfony console |
| candy-sprinkles | medium | 43 | backed-enum, enum, ctor-promotion, match, mixed, nullsafe |
| candy-testing | medium | 8 | **fiber**, attribute, ctor-promotion, enum-in-const-expr, first-class-callable, intersection |
| candy-tetris | medium | 14 | attribute, backed-enum, ctor-promotion, enum-with-methods, first-class-callable |
| candy-vcr | medium | 81 | attribute, backed-enum, ctor-promotion, match, named-args, new-in-init; symfony |
| candy-wish | medium | 35 | ctor-promotion, match, mixed, named-args, non-capturing-catch |
| sugar-charts | medium | 28 | ctor-promotion, enum-with-methods, match, named-args, php8-strfn |
| sugar-glow | medium | 10 | attribute, ctor-promotion, match, mixed, named-args, readonly |
| sugar-readline | medium | 17 | ctor-promotion, match, new-in-init, php8-strfn |
| sugar-skate | medium | 8 | ctor-promotion, match, mixed, named-args, php8-strfn, readonly; sqlite3 |
| sugar-spark | medium | 8 | ctor-promotion, match, php8-strfn, readonly |
| sugar-stickers | medium | 7 | enum, first-class-callable, match, php8-strfn, readonly-class |
| sugar-wishlist | medium | 6 | ctor-promotion, first-class-callable, match, mixed, named-args; pcntl |
| super-candy | medium | 14 | backed-enum, ctor-promotion, enum-with-methods, match, named-args |
| candy-ansi | low | 11 | attribute, backed-enum, ctor-promotion, enum-with-methods, fiber*, first-class-callable |
| candy-fuzzy | low | 6 | ctor-promotion, named-args, readonly |
| candy-layout | low | 13 | ctor-promotion, enum, readonly-class, readonly |
| candy-metrics | low | 13 | ctor-promotion, non-capturing-catch, readonly, callable-type, void |
| candy-mold | low | 1 | ctor-promotion, match, readonly |
| candy-zone | low | 14 | ctor-promotion, match, php8-strfn, readonly |
| honey-flap | low | 6 | ctor-promotion, named-args, readonly |
| sugar-boxer | low | 3 | attribute, ctor-promotion, first-class-callable, intersection, match |
| sugar-calendar | low | 6 | ctor-promotion, match, nullsafe, php8-strfn, readonly-class |
| sugar-crumbs | low | 8 | ctor-promotion, match, mixed, nullsafe, readonly |
| sugar-prompt | low | 23 | enum, first-class-callable, mixed, nullsafe, php8-strfn |

*`candy-ansi` fiber appears in audit; confirm at step time — may be a feature‑detected path.*

## 9. Appendix B — Dependency tiers (topological)

- **Level 0 (no sugarcraft deps):** candy-ansi, candy-async, candy-buffer, candy-fuzzy,
  candy-input, candy-layout.
- **Keystone (cyclic):** candy-core ↔ candy-pty (+ candy-sprinkles right after).
- **Level 1 (everything else):** depends on core/buffer/sprinkles/etc.; backport strictly after
  its listed deps. Full per‑lib dep lists are in the audit (see `plans/PHP74_BACKPORT.md`).

Cross‑lib ripple to watch: enum `::Case` → `::Case()` (§4.3) and any consumer typing against a
backported union/`static`/`mixed` signature. Strict tier order + per‑step `composer update`
guarantees a consumer never compiles against an un‑backported dependency.
