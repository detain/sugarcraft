# Plan: Remediate every finding in `missing.md`

> **Deliverable:** on approval, this document is saved to `/home/sites/sugarcraft/plan_missing.md`.
> It is the step-by-step fix plan for the 59-section audit in `/home/sites/sugarcraft/missing.md`
> (58 libraries + docs/website).

---

## Context

`missing.md` is the output of a 59-agent parallel audit of the SugarCraft monorepo. Per library it
catalogs seven finding classes — missing/incomplete functionality, performance, test-coverage gaps,
missing VHS demos, security, cross-lib duplication, documentation — each with `file:line` citations. It
is a *diagnosis*; it contains no fixes and no ordering. This plan turns that diagnosis into an
executable, sequenced remediation program. Two realities shape it:

1. **The highest-value findings are cross-cutting, not per-lib.** The same defects recur across many
   libraries — a parser fork with a regressed bug, four divergent text-sanitizers, two independent
   ports of bubblezone, seven `dracula()` palettes, a shared test harness almost nobody uses, unused
   path-repo dependencies, an atomic-JSON-save copied into 8+ libs, three status/help bars, a
   duplicated toast engine. Fixing these once, in the owning foundation lib, retires dozens of
   individual findings and prevents their re-introduction.
2. **"Everything" is a phased roadmap, not one change.** The plan is tiered by severity and sequenced by
   dependency so the repo's documented **ship-as-you-go** cadence (2–4 related items per PR, dependency
   order, `ai/<slug>-<short>` branches, every touched lib green before merge) can execute it
   incrementally.

**Intended outcome:** every `missing.md` finding is either fixed, converted to a tracked backlog item
with a concrete fix step, or explicitly marked "won't fix / out of scope" with a reason — security and
correctness first, then de-duplication, then coverage/docs/polish.

**After this document is written:** execution is a separate, phased effort. Nothing here is auto-started;
Phase 0 (below) is the safe place to begin on your go-ahead.

---

## How to read this plan

- **Part A — Cross-cutting workstreams (W1–W18):** architectural fixes that span libs. Do these first;
  they unblock and shrink the per-lib work.
- **Part B — Security fixes** and **Part C — Correctness/broken-shipping bugs:** the P0 spine, viewed by
  severity for triage. Every item also appears in its lib's Part D section for execution.
- **Part D — Per-lib backlogs:** the authoritative, self-contained per-library task lists (every finding,
  `file:line`, concrete fix). This is what you work from lib-by-lib.
- **Part E — Sequencing & PR bundling** and **Part F — Verification.**

**Tag legend:** `[SEC]` security · `[BUG]` correctness/broken · `[PERF]` performance · `[DEDUP]` cross-lib
duplication · `[FEAT]` missing functionality · `[TEST]` coverage · `[VHS]` demo · `[DOC]` documentation ·
`[DEADCODE]` dead/unused code.

**Conventions every fix must honor** (from `AGENTS.md`/`CLAUDE.md`): `declare(strict_types=1)` first
line; immutable + fluent `with*()` via the `mutate()` helper (`candy-core/src/Concerns/Mutable.php`);
bare accessors (no `get`); `::new()` factory; `Mirrors charmbracelet/<repo>.<Method>` doc-comments;
PHPUnit 10 tests in `<lib>/tests/`; VHS `.tape` under `<lib>/.vhs/` rendered by **candy-vcr**; the TEA
test harness is **candy-testing**. Never "fix" a library by changing its actual scope (several audit
prompts guessed a lib's purpose wrong — act on the findings, not the guesses; see Appendix).

**Pre-flight for any local test run:** per-lib `composer.lock`/`vendor/` go stale (gitignored; CI
unaffected). Run `composer update` in a lib before trusting a local `vendor/bin/phpunit` failure.

**Priority tiers:** P0 — security & broken-shipping (Parts B, C); P1 — cross-cutting de-dup & foundation
correctness (Part A); P2 — per-lib functionality + performance (Part D); P3 — coverage, VHS, docs,
dead-code (Part D).

---

## Part A — Cross-cutting workstreams

Each names the **owning lib**, the **resolution**, the **migration mechanics**, and the findings it
retires. These are the spine; Part D references back to them.

### W1 — De-fork the ANSI parser: candy-vt ⇒ candy-ansi  `[BUG][DEDUP][SEC]`
`candy-vt/src/Parser/` carries a fork of candy-ansi's `Parser`/`CsiHandler`/`CsiHandlerImpl` that
**regressed the exact bug candy-ansi fixed**: `start()` (`candy-vt/src/Parser/Parser.php:198-205`, line
200) still does `$this->stringBuffer = '';`, discarding the lead byte of DCS/OSC/SOS/PM/APC sequences
(candy-ansi removed this per its 2026-05-30 "step-20" learning; the fix repaired sugar-spark/candy-hermit/
candy-freeze). candy-vt also declares path-repos on candy-ansi (`composer.json:97`) and candy-buffer
(`:29,55`) its `src/` never imports.
**Owner:** candy-ansi owns the parser state machine; candy-vt keeps only emulator semantics.
1. **Immediate hotfix (P0):** delete the `$this->stringBuffer = '';` line; add a regression test feeding
   a SOS/PM/APC payload containing an 8-bit C1 re-introducer (0x98/0x9E/0x9F) and asserting the payload
   survives; add the same to `FuzzerTest.php`. Ship alone, first.
2. Reconcile the string-buffer cap (candy-vt 1 MiB vs candy-ansi 64 KiB) → make it a constructor param
   defaulting to 64 KiB; document in both CALIBER_LEARNINGS.
3. **Complete "step-12":** grow candy-ansi's `CsiHandler` with `cr()`/`lf()` + the CSI finals candy-vt
   implements (S/T scroll, L/M IL/DL, @/P ICH/DCH, b REP, s/u SCO), route C0 CR/LF in
   `HandlerAdapter::execute()`, then reimplement candy-vt's ~280-line `CsiHandlerImpl` behind that
   interface and delete `candy-vt/src/Parser/{Parser,Transitions}.php`. Gate deletion on candy-vt's
   `ParserTest`+`FuzzerTest` passing against the candy-ansi parser.
4. **Buffer question:** candy-vt needs a mutable/resizable grid; candy-buffer is immutable. Recommend
   (now) dropping the unused candy-buffer path-repo from candy-vt and documenting the split; (later RFC)
   add a mutable builder + `resize()` to candy-buffer and adopt.
5. While here, fix candy-ansi's OSC-8 routing gap (`HandlerAdapter::oscDispatch()` never calls
   `OscHandler::hyperlink()`; `OscHandlerImpl::hyperlink()` is a no-op) and reconcile README 🟡 vs
   `MATCHUPS.md:45` 🟢.

### W2 — One text-sanitizer, owned by candy-core  `[SEC][DEDUP][TEST]`
Four divergent sanitizers: `candy-core/src/Util/Sanitize::controlChars()` (strips C0, preserves
ESC/SGR — and has **zero tests**), `sugar-table/src/Sanitize::value()` (visible `·`/`→` glyph
replacement + UTF-8 repair), `candy-query/src/CellValue::sanitize()` (near byte-identical twin of
sugar-table's), `sugar-dash/src/Output/Sanitize::untrusted()` (full ANSI strip + lone-C1 UTF-8-aware
byte scan — the most intricate).
**Owner: candy-core.** Grow `Sanitize` into the canonical layered primitive: a C0/C1/DEL core plus
policies — `controlChars()` (ESC-preserving, keep), `cellValue(preserveNewlines)` (glyph-replacement),
and `untrusted()` (port sugar-dash's C1-aware scan **verbatim** so it exists exactly once). Add the full
test matrix candy-core is missing (`\x7f` DEL, lone C1, ESC on/off, multiline, invalid-UTF-8 iconv vs
mb path). Then sugar-table/candy-query delegate to `cellValue()`, sugar-dash delegates to `untrusted()`,
and new consumers (sugar-crumbs, sugar-skate `sanitizeForTty`, candy-log formatters) adopt — all keeping
their public signatures. Apply it at candy-core's own paste boundary (see Part B/candy-core).

### W3 — Merge the two bubblezone ports: candy-mouse ↔ candy-zone  `[DEDUP][SEC]`
Two complete, **non-interoperable** ports of `lrstanley/bubblezone`: candy-mouse (PUA U+E000/E001
markers, `Mark`/`Scan`/`Zone`/`ZoneClickTracker`, already carries the click-dedup fix) vs candy-zone
(APC `\x1b_candyzone:` markers, richer `Manager`/`Zones`/hover/drag/multi-click). `MATCHUPS.md:32-33`
lists both 🟢 for the same upstream. Three consumers require both; in sugar-crumbs and sugar-veil the
candy-zone `Manager` is **inert** (stored, never called).
**Owner: candy-mouse owns the low-level Mark/Scan/Zone hit-test primitive; candy-zone becomes the
TEA-facing façade (Manager/hover/drag/multi-click) that delegates to it** (deprecate the APC marker
scheme and `ClickCounter`'s dup of `ZoneClickTracker`).
1. **Quick win (P1):** drop candy-zone dependency + import from `sugar-crumbs/src/Breadcrumb.php`
   (`withZoneManager()` never calls a Manager method) and `sugar-veil/src/Veil.php` (`$manager` inert) —
   verified no external `withZoneManager`/`withManager` consumers exist; zero behavior change.
2. Rebuild candy-zone `Manager::mark()/scan()` on candy-mouse `Mark`/`Scan`; make `Zone\Zone` a BC
   adapter over `Mouse\Zone`; `ClickCounter` delegates to `ZoneClickTracker`.
3. Migrate sugar-bits (third dual-dep consumer). Land the shared unescaped-`id` sentinel-injection guard
   once in `Mouse\Mark::wrap()` (reject/strip U+E000/E001 in caller ids). Update `MATCHUPS.md` to one
   canonical port + layered "see also" in both READMEs.

### W4 — Fuzzy-matching SSOT: candy-fuzzy  `[DEDUP][SEC][PERF]`
`candy-fuzzy` is the intended SSOT but is behind its own duplicates: `candy-lister/src/FuzzyMatch.php`
(bit-identical Smith-Waterman, and *ahead* — ships a `ScoringProfile` feature candy-fuzzy lacks) and
`candy-forms/src/Fuzzy/FuzzyMatcher.php` (`@deprecated` but a real independent impl). `sugar-prompt` is
already a genuine `class_alias` shim (verified — the "fourth duplicate" suspicion was false).
`candy-fuzzy`'s `SmithWatermanMatcher` allocates two O(n·m) arrays per call with **no length cap**
(algorithmic-DoS) and no memoization.
**Owner: candy-fuzzy.** (1) Port `candy-lister`'s `ScoringProfile` (default/strict/lenient) into
`SmithWatermanMatcher` as constructor-injected scores (defaults = current constants, bit-equivalence
tested); optionally a two-row `withoutTraceback()` mode. (2) Add length caps + early-exit pruning + an
incremental/memoized filter for keystroke-driven queries. (3) Convert candy-lister `FuzzyMatch` and
candy-forms' class to real delegating shims (mirror sugar-prompt, guarded by `AliasResolutionTest`),
wiring `sugarcraft/candy-fuzzy` via the path-repo-closure flow; they gain `matchedIndices`/highlighting
for free. (4) candy-files search adopts candy-fuzzy. (5) Add
`SugarCraft\Async\OperationCancelledException` to **candy-async** (referenced by candy-lister but
nonexistent; throw it in `AsyncOps` retry/cancel paths — safe subclass of `\RuntimeException`).

### W5 — Palette/theme SSOT: candy-palette constants + candy-sprinkles Theme  `[DEDUP][DOC]`
Seven independent `dracula()` (and sibling `oneDark`/`githubDark`) factories hand-copy hex values
(candy-sprinkles, candy-kit, candy-shine, candy-forms, candy-freeze, candy-vt, sugar-dash), guaranteeing
drift — candy-kit's dracula accent already diverged (`#bd93f9` vs sprinkles' `#ff79c6`). Some *API*
divergence is intentional (sugar-dash records `[pattern:dual-theme-ssot]`; slot counts genuinely differ
per lib).
**Do NOT unify the Theme classes.** Two-layer SSOT: **candy-palette** gains a named-hex constant source
(e.g. `Palettes::DRACULA`) — a natural fit alongside its colorprofile role; **candy-sprinkles `Theme`**
stays the canonical semantic value object and derives its hexes from those constants. Every lib's
`dracula()` factory reads the constants instead of literals. Migration: add constants + one cross-lib
test asserting each lib's `dracula()` hexes match; fix the stale `candy-sprinkles/CALIBER_LEARNINGS.md:6`
alias claim; mechanical per-lib literal→constant swap; document deliberate slot-count divergences.

### W6 — Adopt the candy-testing harness  `[TEST][DEDUP][SEC]`
`ProgramSimulator`/`ScriptedInput`/`TapeRecorder`/`assertCellGrid`/`TestResult` are almost unadopted:
~80 files hand-roll `KeyMsg` sequences (because `ProgramSimulator::for()` only accepts `Program`, not
`Model`), ~162 hand-roll temp-dir setup that `TemporaryDirectoryTrait` solves but is stranded in
`tests/` under `autoload-dev`. Only `assertGoldenAnsi` is broadly adopted (18 libs). `GoldenFile::resolve()`
lacks a path-traversal guard; `TestResult` cmd-assertions throw `RuntimeException` instead of
`Assert::fail()` and are untested.
**Owner: candy-testing.** (1) Promote `TemporaryDirectoryTrait` to `src/Concerns/` (public namespace);
(2) add `ProgramSimulator::for(Model|Program)` overload + push `ScriptedInput::key()`; (3) fix
`TestResult` to route through `Assert::fail()` + test it; (4) add the `GoldenFile::resolve()` traversal
guard; (5) add a `view()`-idempotence / clone-equality assertion helper (supports W13). Then migrate the
~162 temp-dir and ~80 KeyMsg sites **opportunistically** as each lib is touched — not in one sweep.

### W7 — Prune unused path-repo dependencies  `[DEADCODE][DEDUP]`
Confirmed unused `sugarcraft/*` requires + path-repos: candy-vt→candy-ansi/candy-buffer,
candy-input→candy-ansi/async/pty, candy-lister→candy-input/pty/async, candy-mouse→candy-ansi/async/input/pty,
candy-palette→candy-sprinkles, candy-freeze→candy-pty, sugar-boxer→candy-layout (until W12).
**Owner: repo tooling then per-lib.** Extend `scripts/check-path-repos.php` with an `--unused` pass (for
each `require sugarcraft/x`, grep the lib's `src/` for the dep's PSR-4 namespace; flag zero-hit deps not
needed transitively) and wire it into CI. Then prune each hit (remove require **and** its
`repositories[]` entry); re-verify closure with `check-path-repos.php`. Do **not** prune deps a
workstream is about to start using (sequence after W1/W12).

### W8 — Shared durable-state helpers in candy-core  `[SEC][DEDUP][BUG]`
Two recurring patterns: an atomic tmp+flock+rename JSON save copied into 8+ libs (candy-mines,
candy-hermit, candy-metrics, sugar-dash ×2, candy-wish, sugar-crush, sugar-readline, sugar-tick), and
`json_decode(...)` sites with no `is_array()` guard before casting (sugar-skate importers,
sugar-wishlist `Config.php`, candy-mines `DifficultyStats::load()` uncaught `JsonException`, honey-flap).
**Owner: candy-core** (the near-universal dep). Add (a) `Util\AtomicJsonFile` — `::new(path)`,
`read(): array`, `write(array)` (tempnam-in-same-dir + flock + rename, `JSON_THROW_ON_ERROR`, optional
base-dir confinement); and (b) `Util\Json::decodeArray(string): array` (throws `\RuntimeException` on
non-array top level). Land helpers + tests first, then migrate consumers one lib per PR (each keeps its
public API). PrometheusFileBackend adopts only the write primitive. The base-dir-confinement flag also
retires candy-hermit's `FileHistory` path SEC note.

### W9 — Shared syntax-highlighting primitive: candy-shine ↔ candy-freeze  `[DEDUP][SEC]`
Not duplicate copies — **complementary halves**: candy-shine owns the only source tokenizer
(`SyntaxHighlighter.php`, regex, 8 langs, colors hardcoded inline, ReDoS-prone at :142); candy-freeze
owns token→color theme mapping (`ChromaThemeLoader`/`VsCodeThemeLoader`) but can only consume
already-ANSI input. **Resolution:** extract a shared `Highlighter` interface emitting `TokenSpan`s (wrap
candy-shine's tokenizer, ideally pluggable to shell out to `chroma`) + a token→Style theme layer
(candy-freeze's loaders). candy-shine stops hardcoding colors; candy-freeze gains plaintext highlighting.
Harden the regexes (bounded quantifiers, input-length cap) as part of this. Sequence after W1.

### W10 — candy-serve ↔ candy-wish SSH overlap  `[SEC][DEDUP]`
`candy-serve/src/SSH/SSHServer.php` reinvents a weaker slice of what candy-wish provides
(ForceCommand/Transport/Middleware/Session), with a null-key auth bypass, and doesn't require candy-wish.
**Resolution:** candy-serve depends on candy-wish and re-expresses its SSH surface as a candy-wish
middleware/handler — simultaneously closing candy-serve's "no real SSH transport" gap and the auth
bypass. Sequence with the candy-serve security fixes (Part B).

### W11 — sugar-glow ⇒ candy-shine composition (delete dead reimpl)  `[DEADCODE][DEDUP]`
`sugar-glow/src/GlamourTheme.php` + `src/Highlighter/*` (~300 src + ~330 test lines) and `src/Pager.php`
are a fully **dead** parallel stack; the live path already composes candy-shine (`RenderCommand.php:76,85`).
**Resolution:** (1) first add a candy-shine test asserting ESC bytes in fenced code are stripped (port
the one valuable idea from `ChromaJsonHighlighter.php:110-113`); (2) delete `GlamourTheme.php`,
`Highlighter/`, dead `Pager.php`, and their tests; (3) purge `README.md:75-131` + stale
`CALIBER_LEARNINGS.md:8`.

### W12 — Fix candy-layout's solver, then reuse it in sugar-boxer  `[BUG][DEDUP][PERF]`
`candy-layout`'s `CassowarySolver` **never converges** (own comment `src/CassowarySolver.php:306-319`
admits hitting the 1000-iteration cap every call and silently returning partial state) + a
Ratio-returns-0 bug (documented downstream in `candy-sprinkles/.../SolverFactory.php`) + a
division-by-zero when all `Fill` weights are 0 (`GreedySolver.php:307-310`) + a `Region` class-name
collision with candy-buffer. sugar-boxer reimplements the same distribution and never uses its declared
candy-layout dep.
**Owner: candy-layout.** (1) **Decision: converge-or-deprecate** — either rewrite the Big-M simplex with
a real Phase-1/Phase-2 convergence exit, or hard-deprecate `CassowarySolver::solve()` to delegate wholly
to `GreedySolver` with `E_USER_DEPRECATED` (do not keep shipping silent-wrong-answers). (2) Guard the
all-`Fill`-0 division (equal shares fallback); fix Ratio-returns-0 if rewriting; validate negative
`Region` dims. (3) **Region collision:** no rename (leaf-package rationale is sound) — add reciprocal
"not the same class as…" docblocks/READMEs in both libs and a single `RegionBridge` converter in
candy-sprinkles (already depends on both). (4) Once correct, migrate `sugar-boxer::distribute()/
distributeFlex()` onto candy-layout (freeze behavior with goldens first; fix the margin/`totalWidth()`
bug first since it changes goldens); then W7 keeps the now-used dep.

### W13 — Fix immutability-convention violations  `[BUG][DEDUP]`
Classes that mutate in `view()`/accessors or bypass `mutate()`: sugar-calendar `DatePicker::View()`
(writes cache fields on `$this`), candy-flip `Player::view()`, candy-kit `Banner` static cache,
honey-bounce `SpringCollection` in-place mutators, sugar-dash `Theme::withX()` (bypasses `mutate()`,
incomplete wither surface), candy-files `Manager` `with*()` (manually re-lists 16 ctor args).
**Resolution:** amend the convention text (AGENTS.md) that render/view/accessor paths must be
side-effect-free; drop caches where render is cheap (calendar grid is O(42)) or hold them in an explicit
external memo (`\WeakMap` side-table), never `$this->x =` in a read path; route all `with*()` through
`mutate()`; complete missing wither fields. Add the W6 `view()`-idempotence assertion helper and fix each
offender as its lib is touched.

### W14 — Docs/website: branch sweep, meta, icons, Codecov, generator  `[DOC][BUG]`
All in `## docs/ website`. `blob/main/` appears **60× across the 58 lib pages** while sibling links use
`blob/master/` (org default flipped to master → these 404); meta/JSON-LD say "33 libraries and 23 apps
(56 packages)" vs the actual 58; Codecov badges absent from all lib pages; `docs/img/icons/` is a stale
non-symlink copy of `media/icons/` (missing `candy-mosaic.png`, orphan `sugar-dash.png` that exists
nowhere else); 5 libs have no icon + 8 are 1×1 stubs (13/58 = 22%); stray `docs/lib/honey-bounce.md`;
11k+ lines of hand-maintained HTML (root cause of the 60× link bug).
1. **P0-ish single sweep:** `blob/main/` → `blob/master/` across `docs/lib/*.html` + a CI grep guard
   forbidding `blob/main/` under `docs/`.
2. Fix the package count to 58 in meta/OG/JSON-LD + visible copy (recount lib/app split from MATCHUPS.md).
3. Add per-lib Codecov badge/link (reuse the README badge URL).
4. Make `docs/img/icons/` a synced copy (or symlink) of canonical `media/icons/`; first copy the orphan
   `sugar-dash.png` back into `media/icons/`, restore `candy-mosaic.png` into the docs copy; add a
   basename-diff CI check.
5. **Icon backfill:** real artwork for the 5 missing (candy-focus, candy-forms, candy-pty, sugar-dash,
   sugar-gallery) + the 8 stubs (candy-ansi/async/buffer/fuzzy/input/layout/mouse/testing); remove the
   `onerror` display crutches; remove stray `docs/lib/honey-bounce.md`; reword the `index.html:367` TODO.
6. **Strategic (RFC, sequence last):** `tools/gen-docs.php` rendering lib pages from a shared template +
   per-lib `composer.json`/README/MATCHUPS data (nav, search, head, badges, counts all computed), then
   replace the "edit docs/lib/<slug>.html by hand" checklist steps. Optionally surface MATCHUPS status
   badges + breadcrumbs.

### W15 — Standardize orphaned-feature wiring & CLI smoke tests  `[BUG][TEST]`
Multiple apps ship features implemented + unit-tested but never wired into their entrypoint, and the
entrypoints are untested — so they ship broken: sugar-skate `bin/skate` (fatal — see Part C), sugar-tick
(`SugarTrackIgnore`/`SqliteBackend`/`Milestone` unwired), candy-mines (`Stats`/`CustomDifficulty`
unwired), candy-tetris (`startWithLockDelay()` unused), candy-wish (broken example — Part C).
**Resolution:** per app, wire the feature into the entrypoint or remove it, **and** add a `bin/*`/example
smoke test (the missing coverage that let these ship). Concrete items live in Parts C and D.

### W16 — Shared single-line status/help bar primitive  `[DEDUP]`
`candy-hermit/src/{StatusBar,HelpBar}.php`, `sugar-dash/src/Components/StatusBar/StatusBar.php`, and
`sugar-crush/src/Tui/Components/StatusBar.php` triplicate a themed single-line bar. **Owner:**
candy-sprinkles (all three already depend on it) — extract a shared bar primitive; consumers keep thin
themed wrappers. Migrate hermit/dash/crush in follow-up PRs with golden-snapshot updates.

### W17 — Delete duplicate façade test suites  `[TEST][DEADCODE]`
`sugar-bits/tests/{TextInput,TextArea,ItemList,Viewport,FilePicker,Cursor,Scrollbar,Spinner}/` are
**2,867 LOC byte-identical** to candy-forms' suites except the namespace line, re-running the same tests
against `class_alias` re-exports (zero incremental coverage, double CI cost, silent-drift risk).
**Owner:** canonical tests live only with the canonical impl (candy-forms). Delete the 8 copied dirs; add
one `tests/AliasesTest.php` asserting each alias resolves to its `SugarCraft\Forms\*` class. Document the
rule ("façade = alias smoke test only") in AGENTS.md; grep for the same pattern in any other alias façade.

### W18 — Shared toast/notification engine: sugar-toast owns  `[DEDUP]`
`sugar-dash/src/Components/Toast/*` (Toast 449 LOC + Notification/NotificationQueue/NoticePosition/Level)
independently reimplements queueing/severity/position that **sugar-toast** already owns with a richer
feature set (timers, overflow strategies, actions, history). **Owner:** sugar-toast (engine); sugar-dash's
`Toast::fromNotification()/fromQueue()` become adapters mapping `Level`→`ToastType`,
`NoticePosition`→`Position`, keeping sugar-dash's public `Notification` DTO stable. Migration: add
`sugarcraft/sugar-toast` require + path-repo closure to sugar-dash (none today), adapt, pin with
golden-render tests; interim fallback if deferred: cross-link both READMEs.

---

## Part B — Security fixes (P0)

Worst-first triage view. Each ships as its own small PR with a regression test; full detail in Part D.

- **candy-serve** — `X-CandyServe-User` header ⇒ unauthenticated user impersonation (`Server.php:794-796`,
  bare array lookup, no verification); git receive-pack updates refs **without reading/unpacking the
  packfile** (`GitDaemon.php:765-838`) ⇒ repo corruption; `SSHServer::authenticate()` returns true on
  null key (auth bypass); TLS config fields never wired; StatsServer unauthenticated. (Coordinate W10.)
- **candy-metrics** — cardinality eviction (`Registry.php:258-274`) never calls `Backend::remove()` ⇒
  unbounded backend memory under attacker tags (DoS); `InMemoryBackend::histogram()` unbounded samples;
  SSH `user`/`term` tags unnormalized.
- **candy-pty** — unchecked `FD_CLOEXEC` return (`PosixPtySystem.php:67,102`); slave-PTY-by-path 3× TOCTOU
  (`Spawn.php:62-66`); `SUGARCRAFT_LIBC` env ⇒ unauthenticated code-exec primitive.
- **candy-shell** — `FormatCommand --type template` expands any `{{VAR}}` via getenv (secret exfil);
  `--timeout` declared everywhere, enforced nowhere; `LogCommand` unguarded `sprintf` ⇒ `ValueError`.
- **candy-forms** — `RenderSafe::clean()` ESC-stripping only covers MultiSelect/Confirm/FilePicker, not
  Input/Text/Select/Note/dynamic labels ⇒ terminal-injection; unbounded default `charLimit`.
- **candy-input** — UTF-8 multibyte silently corrupted; `decodeClean()` O(n²) (0.8s @160k) ⇒ CPU-DoS;
  unbounded `$remainder` buffer.
- **sugar-crush** — hooks guarding Bash/Edit/Write are opt-in (unguarded if `withHooks()` skipped,
  `EngineBackend.php:84`); `ConfirmRemoveHook` regex bypassable (long-form flags, `find -delete`); `Bash`
  has no `PathJail`; session/SQLite transcripts written world-readable.
- **candy-log** — log injection via unescaped `\n`/`\r` in Text/Logfmt; raw ESC forwarded; `showLocals`
  dumps secrets.
- **candy-fuzzy** — unbounded O(n·m) allocation ⇒ algorithmic DoS (fixed by W4).
- **candy-shine** — ReDoS-prone highlighter regexes (`SyntaxHighlighter.php:142`); `withSanitize(false)`
  disables stripping globally incl. raw HTML.
- **sugar-post** — port-465 sends plaintext `EHLO`+`STARTTLS` instead of implicit TLS at connect
  (`SmtpTransport.php:96-144`) ⇒ handshake failure + pre-TLS byte leak; opportunistic STARTTLS
  (downgrade-strippable); plaintext password unredacted.
- **sugar-readline** — `FileHistory` temp file chmod'd 0600 **after** content written (world-readable
  TOCTOU + symlink-preplant, `FileHistory.php:127-151`).
- **sugar-calendar** — unvalidated interpolation into `\DateTimeImmutable` (`Navigation.php:39`) ⇒ month
  13/negatives parse as silent date shifts.
- **sugar-stash** — `worktreeAdd` path-traversal (`Git.php:207-212`, only leading-dash guarded); `run()`
  proc_open has no timeout (UI hang).
- **candy-core** — bracketed-paste bytes reach `Model::update()` unsanitized (its own `Sanitize` never
  applied). Fix in W2; add `InputReader` paste test.
- **candy-buffer** — `Cell::rune` no control-char validation (unlike `Hyperlink`) ⇒ raw ESC to terminal.
- **candy-freeze** — weak path guards on `--font`/input/`-o` (symlink-only); font bytes base64-embedded
  unvalidated.
- **candy-testing** — `GoldenFile::resolve()` no traversal guard (W6).
- **candy-mosaic** — no decompression-bomb/dimension cap before GD decode; `fetchUrlSync()` follows
  redirects without re-validating scheme (SSRF).
- **sugar-gallery** — `withStyledTitle()` bypasses C0/ANSI sanitize; unbounded sparse-item cache.
- **candy-files** — `copyDir` no symlink-loop guard (DoS); move/rename fully synchronous.
- **candy-wish** — `RateLimit` fails open on I/O error; per-IP only (no per-account/failed-auth backoff);
  `SSH_PASSWORD` leakable if a spawner middleware precedes `PasswordAuth`; forgeable
  `SSH_USER_KEY_FINGERPRINT` behind a non-sshd front-end.
- **sugar-wishlist** — non-scalar config fields silently `(string)`-cast (`Config.php:218-226`) ⇒
  `ssh user@Array` (W8 is_array pattern).
- **sugar-spark** — `bin/sugarspark` `@file_get_contents($argv[1])` honors `php://`/`http://`/`phar://`;
  DCS payloads interpolated unsanitized.
- **candy-lister** — `applyStyle()` splices raw `$codes` into SGR (`Model.php:779`); unbounded dimension
  allocation.
- **sugar-tick** — `AutoBackup` `@`-suppresses mkdir/copy failures (silent data loss); `Store::append()`
  no `LOCK_EX`.
- **sugar-reel** — `openUrl()` only checks `^https?://` (SSRF via ffmpeg); WebVtt parse uncapped.
- **sugar-toast** — negative-length `substr` on unterminated `\x1b[` under strict_types (crash);
  CSI-discard only accidental.
- **sugar-stickers** — raw style strings into SGR without `^[0-9;]*$` validation.
- **sugar-glow** — theme-config/input slurped uncapped before decode.
- **candy-query** — `QueryLogger` retains raw SQL incl. `IDENTIFIED BY '<secret>'` on the Debug pane.

---

## Part C — Correctness / broken-shipping bugs (P0)

Outright broken or wrong-output today. Each: fix + regression test. Full detail in Part D.

- **candy-files** — trash dir (`/tmp/candyfiles-trash-<pid>`) is **never `mkdir`'d**, so the first
  delete's `@rename` fails and silently falls through to permanent `removePath()` (`Manager.php:463-468`):
  **data loss, and undo is defeated**. `mkdir(0700)` + `random_bytes()` suffix before first use; test the
  first delete lands in trash.
- **sugar-skate** `bin/skate` — `list` calls nonexistent `Store::sanitizeForTty()`; `get`-miss calls
  **private** `Store::suggestSimilar()` ⇒ both fatal. Implement/inline; delete redundant suggestion
  block; add CLI smoke test (W15).
- **candy-vt** — regressed `start()` stringBuffer clear (W1.1). *Highest correctness priority.*
- **sugar-spark** `StreamingInspector::finish()` — dead post-`flush()` state check silently drops a
  trailing bare ESC; dangling SS3 triggers an illegal private/protected-access `Error`. Fix both; add
  end-of-stream tests; add missing `CALIBER_LEARNINGS.md`.
- **candy-wish** `examples/hello-server.php` — `Banner::handle` signature no longer matches `Middleware`
  (missing `Context $ctx`) ⇒ fatals on run. Fix signature; add a `.vhs` tape.
- **sugar-stickers** `Table::sortBy()` — never syncs `Column::sorted()`, so the sort arrow never renders.
  Fix the sync; assert the `▲`/`▼` glyph in a render test.
- **sugar-stash** — `Git::status()` mis-parses renames (`substr($line,3)` ⇒ `"old -> new"`);
  `bin/sugar-stash` refuses to launch in linked worktrees (`is_dir(".git")`). Fix both; add real-`Git`
  integration tests.
- **candy-kit** `Stage::subStepWithProgress()` — `microtime(false)` string arithmetic warns ⇒ fails under
  `failOnWarning=true`. Use `microtime(true)`; add the test.
- **candy-vcr** — `Output <path>` directive parsed then silently discarded (`Compiler.php:141`), masked by
  convention. Honor it (traversal-confined); add a non-default-`Output` test.
- **candy-layout** — solver non-convergence + Ratio-0 + all-Fill-0 div-by-zero (W12).
- **candy-metrics** — cardinality eviction no-op (Part B).
- **candy-input** — UTF-8 corruption (Part B).
- **candy-mines** — win/lose never calls `recordResult()` (Stats system dead); CLI bypasses
  `CustomDifficulty::fromInput()`; `DifficultyStats::load()` throws raw `JsonException`.
- **honey-flap** — `persistHighScores()` never called (dead), duplicated by an inline closure; no test
  drives a collision/crash/score-increment.
- **sugar-post** — SMTP 465 plaintext STARTTLS (Part B).
- **sugar-crumbs** — `NavStack::filter()` fatals (`\Error`) on array `data` (the documented example
  pattern); `truncate()` keeps an overlong final crumb, busting `setMaxWidth()`.
- **candy-flip** `Decoder.php:312-316` — mixed guarded/unguarded `ord()` in GCE parse ⇒ `TypeError` on a
  truncated GIF instead of the intended `RuntimeException`.
- **sugar-tick** — `export`/`gaps` accept unbounded `$days` (walk 100 years of files); clamp like `push`.
- **candy-async** — dead `$operationPromise;` statement (`AsyncOps.php:115`); `disposeAll()` aborts on a
  throwing `unsubscribe()`, leaking later subscriptions.
- **candy-core** — `installSignalSubscription()` returns a fictitious timer; cancel never detaches the
  `pcntl_signal` handler ⇒ leak/overwrite on start/cancel cycles.

---

## Part D — Per-lib backlogs

Authoritative per-library task lists — work from these lib-by-lib. Bullet form
`[TAG] file:line — problem → fix`, ordered SEC/BUG → PERF/DEDUP/FEAT → TEST/VHS/DOC. Items also in Parts
A/B/C are kept here (grouped by lib for execution) and cross-reference their workstream.

### candy-ansi
- [BUG] `HandlerAdapter.php:79-85` — `oscDispatch()` regexes only `^([0-2]);`, so OSC 8 never reaches
  `OscHandler::hyperlink()` (dead API) → add `^8;([^;]*);(.*)$` branch calling `hyperlink()`, make
  `OscHandlerImpl::hyperlink()` store instead of no-op. (W1.5)
- [BUG] `HandlerAdapter.php:33-41` — `execute()` no-ops CR and ignores LF/VT/FF; no `cr()`/`lf()` on
  `CsiHandler` to route to → add them to the interface and dispatch 0x0D/0x0A-0x0C (W1.3 prerequisite).
- [FEAT] `HandlerAdapter.php:50-72` — `csiDispatch()` lacks S/T, L/M, @/P, b, s/u arms → add interface
  methods + match arms so candy-vt's `CsiHandlerImpl` can implement this interface (W1.3).
- [PERF] `Parser.php:53-59` — one round-trip per plain-ASCII byte → add a Ground-state fast path scanning
  runs of `0x20-0x7E` and dispatching the run in one batch.
- [DEADCODE] `CsiHandlerImpl.php`/`OscHandlerImpl.php` — inert "step-12 pending" stubs → after W1, adopt
  candy-vt's real impl here or delete both stubs + their 17 `assertTrue(true)` tests.
- [TEST] `tests/HandlerAdapterTest.php` — no OSC-8 test → add one feeding `\x1b]8;;https://example.com\x1b\\`
  asserting `hyperlink()` fires (with the OSC-8 fix).
- [TEST] `Parser.php:79-95` — `flush()` DCS/SOS/PM/APC branches untested at EOF → add `parseComplete()`
  tests for an unterminated DCS with `replaceMalformed=true`.
- [TEST] `Transitions.php:40-48` — bit-packing helpers only tested transitively → add a round-trip test
  pinning `action << 4 | nextState`.
- [DOC] README 🟡 vs `MATCHUPS.md:45` 🟢 → keep 🟡 until step-12; add a CALIBER entry noting the fix must
  mirror into candy-vt until then; document `MAX_STRING_BUFFER`/`MAX_PARAMS`/`MAX_PARAM_VALUE`; confirm
  the vhs.yml exemption alongside candy-pty/candy-testing.

### candy-async
- [BUG] `AsyncOps.php:115` — dead `$operationPromise;` statement → delete. (Part C)
- [BUG] `AsyncOps.php:134` — `retryAttempt()` hardcodes `Loop::get()` while siblings accept
  `?LoopInterface` → add the param per the CALIBER loop-injection rule (unblocks mock-loop tests).
- [BUG] `Subscriptions.php:85-91` — a throwing `unsubscribe()` aborts `disposeAll()` → wrap each in
  try/catch, dispose all, rethrow the first. (Part C)
- [SEC] `AsyncOps.php:87-138` — retry has attempt cap but unbounded 2^n wall-clock, no jitter → add
  `?float $maxTotalSeconds` and `float $jitter`.
- [FEAT] `AsyncOps.php:111,125` — bare `\RuntimeException('Retry cancelled')` → add
  `OperationCancelledException` and throw it. (W4.5)
- [FEAT] `AsyncOps.php:162-207` — `debounce()`/`throttle()` return bare closures with no disposal → return
  `DebouncedCallable`/`ThrottledCallable` with `cancel()`/`flush()`.
- [TEST] `retry()` never tested with a `CancellationToken`; `CancellationToken::fireCallbacks()` error
  aggregation untested; throttle trailing-drop + multi-arg untested; `Suspended` throwing-resume/non-array
  untested → add all.
- [DOC] stale `markCancelled()` docblocks (real: `acceptCancellationSource()`, `CancellationToken.php:46`);
  README omits `retry()`'s `$token`, `Suspended`, `Subscriptions::count()/isEmpty()`;
  CALIBER cites wrong FQCN `AsyncOps\TimeoutException` → fix.

### candy-buffer
- [SEC] `Buffer.php:550`/`Diff/DiffEncoder.php:142` — `Cell::rune` written verbatim, no control-byte guard
  (unlike `Hyperlink`) → validate/reject `\x00-\x1f`/`\x7f` in `Cell::new()` (continuation sentinel
  excepted) + test, or document an explicit "caller sanitizes" decision. (Part B)
- [BUG] `Region.php:19-23` — negative dims accepted, silently no-op in `fill()`/`copy()` → throw
  `InvalidArgumentException` (document zero-area as explicit no-op) + tests.
- [FEAT] `Buffer.php` — no `resize()` → add `resize(int,int): self` preserving content, padding with
  `Cell::new()` (W1.4 / candy-vt parity).
- [FEAT] `Buffer.php:109-117` — `withCellAt()` with a width-2 cell silently corrupts the grid → auto-place
  the continuation cell when `width()===2`, mirroring `applyDiff()`.
- [FEAT] `Buffer.php:169` — no whole-buffer clear → add `clear(?Cell): self`.
- [DEADCODE] `Diff/DiffOp.php:28-43` — deprecated `TYPE_*` constants, zero consumers → delete.
- [TEST] `fill()` and `copy()` have zero tests (incl. copy's out-of-bounds→blank branch); `SgrEmitter`
  `&0xFF` channel wrap unpinned → add.
- [DOC] README API table omits `fill`/`copy`/`fromGrid`/`applyDiff`/`toAnsi`; wide-char continuation
  invariant undocumented; add a "building buffers in bulk (use `fromGrid`)" note steering off O(n²)
  chained `withCellAt()`.

### candy-core
- [SEC] `InputReader.php:47,60-106` — bracketed-paste reaches `PasteMsg`/`update()` raw; `Sanitize` never
  invoked → sanitize paste behind a default-on `ProgramOptions` flag + OSC-52 test. (W2 / Part B)
- [BUG] `Program.php:1190-1207` — `installSignalSubscription()` returns a fictitious timer; cancel never
  detaches the `pcntl_signal` handler → track signo→prev-handler and restore on cancel + test. (Part C)
- [BUG] `Program.php:1053-1086,259-267` — signal delivery depends on the render timer calling
  `pcntl_signal_dispatch()` (degrades at low framerate) → enable `pcntl_async_signals(true)`.
- [PERF] `Renderer.php:343-346` — `$tokenCache` grows unboundedly (leak) → cap with FIFO/LRU (~2k).
- [PERF] `Util/Width.php:19-52` — `Width::string()` re-runs strip+grapheme per call (hottest util) → add a
  bounded static memo.
- [DEDUP] `Util/Sanitize.php` — one of four sanitizers → grow into the canonical policy-parameterized
  primitive; add `AtomicJsonFile` + `Json::decodeArray()` here. (W2, W8)
- [FEAT] `Program.php:1151-1183` — `Kind::Key`/`Kind::Custom` are 100ms/1s polling shims → wire `Kind::Key`
  to the real `InputReader` pipeline, or make intervals configurable + document the stopgap.
- [DEDUP] `ImageLayer`/`ImageOverlay`/`ImagePlacement` — sixel/kitty/iTerm2 code in the minimal runtime →
  maintainer decision: extract to candy-mosaic/graphics lib; record verdict.
- [TEST] `Util/{Clamp,ColorUtil,Validation,Sanitize,NullLogger}` have zero dedicated tests → add
  (byte overflow, `\x7f`, range inclusivity); prove `reconcileWantedSubscriptions()` short-circuit;
  confirm SGR-heavy `diffCells()` fallback + child-reorder unmount/mount cases.
- [VHS] only counter/timer tapes vs 19 examples → add `screen-stack`, `mouse`, `splash`, `tabs`.
- [DOC] README Architecture describes a pre-diff renderer that no longer exists (`:66`); references
  nonexistent `Model\Anonymous` (`:183`, fatal on copy-paste); PHP floor stated 8.1/8.2 vs `^8.3` → fix
  all; add WorkerPool "never deserialize untrusted tasks" callout.

### candy-files
- [SEC/BUG] `Manager.php:463-468,431-438` — trash dir never `mkdir`'d ⇒ first delete `@rename`-fails and
  falls through to permanent `removePath()` (**data loss, undo defeated**) → `mkdir(0700,true)` +
  `random_bytes()` suffix before first use; test the first delete is undoable. (Part C)
- [SEC] `Manager.php:571-596`/`AsyncOps.php:208-234` — `copyDir()` recursion has no symlink-loop/depth
  guard (DoS) → visited-`realpath()` set + depth cap + self-referencing-symlink test. (Part B)
- [BUG] `FsLister.php:28-32`/`Pane.php:71-81` — `isDir` from `lstat` means symlinked dirs can't be entered
  → keep `isLink`, set `isDir = is_dir($path)` + symlink-dir nav test.
- [BUG] `Manager.php:242-263,781-812,950-1043` — `with*()`/tab methods hand-list all 16 ctor params → adopt
  `mutate()`. (W13)
- [PERF] `FsLister.php:17-47` — synchronous `scandir()`+per-entry `lstat()` blocks the loop on huge dirs →
  chunk via `Loop::futureTick`; add `performMoveAsync()` (move/rename still sync).
- [DEADCODE] `Manager.php:598-633,571-596` — sync `performCopy()`/`copyDir()` unreachable → delete.
- [FEAT] `UndoAction.php:93-100` mkdir undo has no producer → add `armMkdir`/`performMkdir`+key or delete;
  `Manager.php:815-828` shallow `str_contains` search → adopt candy-fuzzy (W4); `Entry` no permission
  column though mode is read → add.
- [TEST] zero symlink tests, zero permission-failure tests, tab-bar/search/truncation render unasserted →
  add.
- [VHS] copy/move/rename have no tapes → add. [DOC] README architecture/test-count(36 vs 143)/status
  stale; docs page links a nonexistent `examples/`; only `en` locale → refresh.

### candy-flip
- [BUG] `Decoder.php:312-316` — mixed guarded/unguarded `ord()` in GCE parse ⇒ `TypeError` on truncated
  GIF → apply `?? ''` uniformly. (Part C)
- [BUG] `Frame.php:22` docblock says disposal 3 unsupported but `Decoder.php:101-104` implements it → fix
  docblock (gated by the pixel test below).
- [PERF] `Player.php:94` — `view()` recomputes full render each call → memoize per (frame,preset,size),
  actually implementing the `weakmap-frame-cache` CALIBER claims exists. (W13)
- [PERF] `Decoder.php:128-137,230-251` — per-pixel `imagecolorat`/`imagesetpixel` + `imagecolorallocate`
  in the inner loop → composite via truecolor canvases + `imagecopy`.
- [FEAT] truecolor-only SGR → add an `ansi256` preset; add `withSpeed(float)` + `+`/`-` keys.
- [TEST] disposal=3 restore semantics (pixel-level), `$cellsW<=0` branch, `bin/candy-flip` argv/fallback
  all untested → add.
- [DOC] `CALIBER_LEARNINGS.md:14,20` — `floyd-steinberg-dithering`/`weakmap-frame-cache` describe code that
  doesn't exist → implement or delete; `composer.json:11,13` duplicate `"sugarcraft"` keyword → dedupe.

### candy-focus
- [DEDUP] `FocusRing.php` — zero consumers while `candy-forms/src/Form.php:776-797` reimplements
  enabled-skip wrap traversal → make Form compose over `FocusRing`; candy-focus stays owner. (see candy-forms)
- [PERF] `FocusRing.php:213-219,258-264` — `next()`/`previous()` rebuild `$enabledPositions` O(n) per
  keystroke → maintain incrementally in disable/enable/register/unregister via `mutate()`.
- [FEAT] no modal focus-trap/sub-ring → add `push()`/`pop()` scope helpers restoring parent focus.
- [TEST] `jsonSerialize()`, `getIterator()`, `disabledIds()`/`enabledCount()`/`disabledCount()` zero tests
  → add.
- [DOC] README API table omits half the methods + disabled-skip semantics; `docs/index.html:139,439`
  references a nonexistent `candy-focus.png` → generate the icon (W14); add CALIBER entries for
  `ofStrict`/`reorder`/disable-enable.

### candy-forms
- [SEC] dynamic titles/descriptions + Select option labels still emit raw ESC (43201eee `RenderSafe::clean()`
  only landed in FilePicker/MultiSelect/Confirm) → apply `RenderSafe::clean()` (or W2) uniformly across all
  render paths + per-field ESC-injection tests. (Part B)
- [SEC] `TextInput.php:91`/`TextArea.php:82` — `charLimit` defaults to unlimited → set sane defaults
  (4096/65536) with `withCharLimit(0)` opt-out.
- [BUG] `Field/Input.php:393` — validator chain runs every keystroke; `TextInput\ValidateOn::{Blur,Submit}`
  gating unreachable from the Field layer → add `Field\Input::withValidateOn()` (and Text), killing the
  per-keystroke ReDoS cost.
- [BUG] `Field/Confirm.php:59` — `withValidator(?\Closure)` rejects `Validator` instances (unlike Input) →
  widen to `Validator|\Closure|null`.
- [FEAT] `Field/Select.php:376`, `Field/FilePicker.php:93`, `Field/MultiSelect.php:72` — no general
  `withValidator()` hook → add.
- [DEDUP] `Form.php:776-797` — bespoke `advance()`/`firstNonSkippable()` traversal duplicates candy-focus →
  refactor onto `FocusRing` (field-level focus state stays local). Convert `Fuzzy/FuzzyMatcher` to a
  candy-fuzzy shim (W4).
- [SEC] `Validator/Pattern.php` — arbitrary caller PCRE, no complexity guard → document ReDoS + add a
  backtrack-limit guard.
- [TEST] `accessible()`/`accessibleView()` zero tests; Input's `Validator`-instance branch never hit; pin
  Select/FilePicker no-op contracts as regressions → add.
- [VHS] no `.vhs/`, no `examples/`, absent from vhs.yml despite being a flagship visual lib → add
  `examples/form.php` + `.vhs/form.tape` + matrix entry.
- [DOC] README never documents `ValidateOn`, accessible mode, or the per-field validator matrix → add.

### candy-freeze
- [SEC] `bin/candyfreeze:69-134,196-200` — path guard blocks only symlinks; input/`--font`/`--output`/theme
  paths unconfined; `--font` base64-embedded unvalidated → one `resolveConfinedPath()` (realpath + CWD
  prefix, `--unsafe-paths` opt-out) on all four; sniff font magic bytes. (Part B)
- [BUG] `bin/candyfreeze:96-148` — `LanguageDetector::detect()` result + `-t/--type` flag computed then
  discarded → wire into rendering or remove the flag.
- [BUG] `SvgRenderer.php:82-88` — `withFont()` hardcodes `font/ttf` MIME → sniff format.
- [DEADCODE] `OutputWriter`/`FileOutputWriter`/`StringOutputWriter` — dead abstraction, zero callers/tests
  → wire `renderToStream()` (also fixes >1MB O(n²) concat) or delete.
- [DEDUP] `PngRenderer.php:76-87` reimplements SvgRenderer window/shadow layout and lacks
  `withHighlight`/`withLigatures`/`withFont` → extract shared frame-layout; join W9 for the tokenizer.
- [PERF] `PngRenderer.php:129,146` — two full canvases for a solid shadow rect → one alpha
  `imagefilledrectangle`.
- [FEAT] unused `candy-pty` path-repo (scaffolding for `--execute`) → implement PTY capture or drop (W7);
  add margin + `--lines start,end` clipping.
- [TEST] `LayoutCalculator`/`WindowChromeGeometry`/`SgrState`/`Theme`/`xterm256ToHex` no direct tests; CLI
  matrix thin → add. [VHS] add png/window-style/line-numbers tapes.
- [DOC] README never mentions PngRenderer/`--format png`/`--window-style`/`--highlight`/`--font`/`-t`,
  theme loaders, or that detected language has no render effect → overhaul; extend CALIBER.

### candy-fuzzy
- [SEC] `Matcher/SmithWatermanMatcher.php:133-136` — dual (n+1)×(m+1) arrays, no length caps → algorithmic
  DoS → add `maxQueryLength`/`maxCandidateLength` (throw, or delegate to `SahilmMatcher` past ~1000). (W4/Part B)
- [BUG] `matchAllGenerator()` (`SmithWatermanMatcher.php:92-109`/`SahilmMatcher.php:109-126`) builds the full
  array before yielding → make it truly lazy or remove from the interface.
- [DEDUP] port `ScoringProfile` from candy-lister; convert candy-forms + candy-lister to delegating shims. (W4)
- [FEAT] scoring constants `private const` → expose via `ScoringProfile`; add a `score()` fast path
  skipping traceback.
- [PERF] full corpus rescan + full sort per keystroke → add incremental-narrowing (fzf-style).
- [TEST] `FuzzyMatcherFactory`, `matchAllGenerator()`, `MatchResultSorter`, `Highlighter` out-of-range
  indices — zero/thin tests → add.
- [DOC] README:82-88 falsely claims candy-forms/candy-lister delegate → fix code then README; document
  `matchAllGenerator`, factory, O(n·m) memory tradeoff.

### candy-hermit
- [BUG] `Hermit.php:530-586` — `withBorder()`/`withStyle()` store decoration but `buildOverlayLines()`/
  `compositeOver()` never read it (README overclaims) → implement border framing + Style in
  `buildOverlayLines()`.
- [SEC] `History/FileHistory.php:20-23` — raw path → `fopen('a')`, no confinement → adopt W8
  `AtomicJsonFile` with base-dir confinement (or realpath prefix check now).
- [PERF] `Hermit.php:512,520` — `View()` builds overlay lines twice per frame → build once; `:517,848`
  `compositeOver()` ignores its `$background` param (dead re-`implode`) → drop it; `:791-824`
  `highlightMatches()` per-char `mb_substr` O(n²) → `mb_str_split` once.
- [DEDUP] `HelpBar.php`/`StatusBar.php` — third bar impl → migrate onto the W16 primitive.
- [TEST] border/style only round-trip-tested; `ttySize()` 80×24 fallback unexercised → add render-level +
  fallback tests.
- [DOC] README/CALIBER claim working border/style composition → reconcile with the fix; document
  `MAX_FILTER_LENGTH` silent-drop.

### candy-input
- [SEC] `EscapeDecoder.php:116,477,494` — unterminated CSI buffers unbounded into `$remainder` → add a
  `MAX_SEQUENCE_LENGTH` (~128) cap; discard/flush on breach + test. (Part B)
- [BUG] `EscapeDecoder.php:134-136` — every printable byte becomes a 1-byte `KeyEvent`, splitting UTF-8 →
  add UTF-8 lead-byte lookahead (0xC2–0xF4), buffer split continuations, emit one event per codepoint. (Part C)
- [PERF] `EscapeDecoder.php:93-140` — `substr($stream,1)` per byte ⇒ O(n²) (0.80s@160KB, CPU-DoS) → rewrite
  as an offset-index walk. (Part B)
- [SEC] `Driver/ReactInputDriver.php:118-133` — no chunk bound (StreamInputDriver caps 8192) → slice to
  ≤8192 before decode.
- [DEADCODE] `EscapeDecoderOptions.php` — never consumed; documented `new EscapeDecoder($options)` can't
  work (no ctor) → add the ctor gating mouse/kitty/focus/paste, or delete.
- [FEAT] `handleKitty()` only bare form → full CSI-u; no X10/URXVT legacy mouse → add or declare non-goal.
- [TEST] zero multibyte coverage; no `ReactInputDriverTest`/`SignalResizeDriverTest`; "fuzz-friendly"
  claim has no harness → add all.
- [DOC] README doesn't disclose UTF-8 handling, EscapeDecoderOptions outcome, legacy-mouse scope, or the
  VHS exemption → document.

### candy-kit
- [BUG] `Stage.php:111` — `microtime(false)*10` string arithmetic warns ⇒ suite-fatal under
  `failOnWarning` → `microtime(true)`. (Part C)
- [BUG] `Frame.php:117-125` — emits all 6 OVERHEAD lines even when `$rows<6`, breaking its own contract →
  throw `InvalidArgumentException` for `$rows<OVERHEAD` + test.
- [SEC] `StatusLine`/`Stage`/`Section`/`HelpText` — caller text into ANSI with no control handling (only
  Frame sanitizes) → route through `Width::truncateAnsi()`+`Ansi::reset()` or add per-class caveat.
- [PERF] `Banner.php:18,32-38` — mutable static `$titleStyleCache` (stale-theme hazard) → delete. (W13)
- [DEDUP] `Theme.php:162-174` — drifted `dracula()` → derive from candy-palette constants + candy-sprinkles
  Theme. (W5)
- [FEAT] no fang-style boxed error → add `ErrorPage::render()`.
- [TEST] `subStepWithProgress($total=0)` (buggy branch), all ThemeBuilder required fields,
  `StatusLine::prompt()` + `Section` `$width=null` goldens → add.
- [VHS] README advertises `logo.gif`/`section.gif`/`stage.gif` that don't exist → add tapes +
  `examples/section.php`/`stage.php` + vhs.yml.
- [DOC] `byName()` omits `'auto'`; note the ratatui `Logo` attribution; add CALIBER entries for the Frame
  invariant + Banner-cache removal.

### candy-layout
- [BUG] `CassowarySolver.php:290-320` — never converges (1000 pivots, silent partial result; fail-fast
  commented out) → **converge-or-deprecate** decision (rewrite Phase-1/2, or delegate to `GreedySolver`
  with `E_USER_DEPRECATED`). (W12 / Part C)
- [BUG] Ratio path returns 0 (documented only downstream) → fix in the rewrite, add a pure-Cassowary Ratio
  test.
- [BUG] `GreedySolver.php:307-310` — `applyMaxClamp()` divides by `array_sum($recipientWeights)` = 0 when
  all `Fill(0)` → guard `<=0`, equal-shares fallback + Max+Fill(0) test.
- [BUG] `Region.php:14-19` — negative x/y/w/h accepted silently → throw.
- [FEAT] no spacing/margin like ratatui → add `withSpacing`/`withMargin`.
- [DEDUP] confirm `candy-sprinkles/Layout/Constraint` is a re-export, not a fork; resolve the `Region`
  name-collision via reciprocal docs + a candy-sprinkles `RegionBridge` converter (no rename). (W12)
- [TEST] add a randomized GreedySolver sum-invariant sweep, degenerate-Ratio, zero-Region edge tests.
- [DOC] record both Cassowary bugs at the source lib's CALIBER; README must state "does not converge; use
  GreedySolver"; drop the wrong "(charmbracelet/bubbletea)" parenthetical at `LayoutSolver.php:14`.

### candy-lister
- [BUG] `FilterState.php:16-20` vs README:116-150 — README documents a `filtered` state the enum lacks and
  `withFilterFn()` never reaches → add the enum case (or fix README+CALIBER; they must agree).
- [SEC] `Model.php:779-787` — `applyStyle()` splices raw `$codes` into SGR → allow only `^[0-9;]*$`. (Part B)
- [SEC] `Model.php:143-151` — `setWidth`/`setHeight` unbounded → clamp/throw.
- [DEDUP] `FuzzyMatch.php:124-147` — third Smith-Waterman copy → depend on candy-fuzzy, upstream
  `ScoringProfile`, convert to a delegating adapter, delete the local DP core. (W4)
- [DEADCODE] `Model.php:329-334,517-522`/`FuzzyMatch.php:59-68` — CancellationToken/`matchAsync()` doc-blocks
  for nonexistent API → delete; prune unused `candy-input`/`candy-pty`/`candy-async` path-repos (W7).
- [PERF] `Model.php:260-264` per-`addItem()` clone ⇒ O(n²) (Quick Start teaches it) → switch example to
  `addItems()`; `bufferFromOutput()` per-cell `mb_substr` → `mb_str_split` once.
- [FEAT] add `withWrapNavigation(bool)`.
- [TEST] `ScoringProfile` presets, `hardWrap()`/`splitOverWidth()` edges — zero/thin → add.
- [VHS] `examples/long-items.php` + a `filtering` demo have no tapes → add. [DOC] add `"fuzzy"` keyword.

### candy-log
- [SEC] `TextFormatter.php:75-89`/`LogfmtFormatter.php:55-61` — embedded `\n`/`\r` pass through (log
  injection); raw ESC forwarded → escape `\n`/`\r`+C0, strip ESC (route via W2). (Part B)
- [SEC] `PanicFormatter.php:147-155` — `showLocals` dumps secrets → document + extend redaction beyond
  `redactPaths`.
- [BUG] `JsonFormatter`/`LogfmtFormatter` ignore `PartsOrder` → honor it or scope PartsOrder to
  TextFormatter in docs.
- [PERF] `Logger.php:175-178` (`debugf`/`infof`/…) — `sprintf` runs before the level check → add
  `enabled(Level)` early-return.
- [FEAT] `\Throwable` in context degrades to class name → structured Throwable rendering; hooks fire only
  via PsrBridge → add `Logger::withHooks()`; add `withCallerSkip(int)`.
- [TEST] filtered `debugf` skip-sprintf, JSON `_encode_error`, `CallerFormatter` null, `HookRegistry::remove()`
  → add.
- [VHS] single `demo.tape` → add `panic-handler` + `formatters` tapes.
- [DOC] `CALIBER_LEARNINGS.md:9` falsely says `remove()` was removed (it exists); README lacks
  StandardLogAdapter/`$forceLevel`/no-redaction caveat → fix.

### candy-metrics
- [SEC] `Registry.php:258-274` — cardinality eviction only unsets the cache, never `Backend::remove()` ⇒
  unbounded backend memory (DoS) → store merged tags as the cache value and call `remove()` on eviction +
  a "series count ≤ limit" test. (Part B/C)
- [SEC] `InMemoryBackend.php:47-50` — unbounded histogram samples → constructor `maxSamplesPerKey` +
  ring/reservoir.
- [SEC] `Middleware/SessionMetrics.php:44` — attacker `user`/`term` tags → cap/normalize length+charset.
- [PERF] `PrometheusFileBackend.php:267-302` `in_array` dirty scans → `isset()` maps; `Registry.php:258-274`
  precompute the default-tags key for the `$tags===[]` fast path.
- [FEAT] no OTLP backend → add `OtlpHttpBackend`; align `StatsdBackend`/`JsonStreamBackend` failure defaults.
- [TEST] rewrite `CardinalityTest.php:73-75` (pins the wrong behavior) to the new eviction contract;
  `MultiBackendException`, `__destruct` flush-failure untested → add.
- [DOC] README + `CALIBER_LEARNINGS.md` (`cardinality-fifo-eviction`) both claim eviction reclaims memory
  (false) → correct.

### candy-mines
- [BUG] `Game.php:87-128` — win/lose never calls `recordResult()`; the whole Stats system is dead from the
  CLI → detect the transition in `update()` and persist via `DifficultyStats` (opt-in from CLI). (Part C/W15)
- [BUG] `bin/candy-mines:24-27` — raw ints bypass `CustomDifficulty::fromInput()`; `Board`'s loose bound
  permits unsafe first-click boards → route through `fromInput()`, tighten `Board` to `w*h-9`.
- [BUG] `DifficultyStats.php:39-76` — `load()` `JSON_THROW_ON_ERROR` uncaught → wrap/rethrow `RuntimeException`
  (W8) + malformed-JSON test.
- [PERF] `Board.php:207-216` `flagCount()` full scan per frame → O(1) counter like `revealedCount`;
  `Renderer.php:175-190` memoize the marked-frame scan.
- [FEAT] add `--easy/--medium/--expert` flags + save/load keybinding.
- [DEDUP] `DifficultyStats.php:118-159` atomic save → W8 `AtomicJsonFile`.
- [TEST] end-to-end win/loss→stats; `status()`/`formatTime()` boundaries → add.
- [DOC] README `Difficulty::$LEVEL` wrong syntax; Stats/save shown as active → fix + document CustomDifficulty
  limits.

### candy-mold
- [DOC] `CALIBER_LEARNINGS.md` missing entirely (AGENTS checklist) → create, seeding the `bin/start`
  autoloader loop, the commented panic-handler opt-in, and the src-only coverage scope.
- [TEST] `bin/start` (first thing every `create-project` user runs) uncovered → add `php -l` + a scripted
  instant-quit exit-0 smoke test.
- [DEADCODE] verify the candy-layout/async/ansi/input/pty path-repos via `check-path-repos.php
  --strict-closure`; prune any not closure-required. (W7)
- [VHS] `.vhs/start.tape` uses a third dim variant (700×240) → normalize to a standard size.

### candy-mosaic
- [SEC] `ImageSource.php:38-211` — no pixel-dimension ceiling before GD decode (decompression bomb);
  `fromUrl()` feeds remote bytes into the same path → gate on `getimagesize*()` result with a `MAX_PIXELS`
  cap (`withMaxPixels()`) before any `imagecreatefrom*`. (Part B)
- [SEC] `ImageSource.php:326-355` — `fetchUrlSync()` follows 5 redirects without re-validating scheme
  (SSRF to 169.254.169.254) → `max_redirects:0` + manual per-hop scheme re-validation.
- [BUG] `AnimationDriver.php:64-79` — `update()` never checks `$paused`, silently un-pausing → early-return
  when paused + test.
- [DEADCODE] `KittyOptions.php:22-24,143-144` — `cellWidth`/`cellHeight` have no setter (s=/v= permanently
  unset) → add `withCellDimensions()` or delete.
- [SEC] `Animation.php` — no frame-count/resolution guard → cap + apply MAX_PIXELS per frame.
- [PERF] `ChafaRenderer.php:82-136` — fresh tempnam + full write per frame → reuse one temp file keyed by
  content hash.
- [TEST] `PrecomputedImage`/`Capability`/`CellSize`/`RenderValidationTrait` no direct tests; add a
  `MosaicTest` for the facade `render()`.
- [VHS] one iTerm2 tape for six protocols → add `halfblock`/`animation` tapes; document sixel/kitty can't be
  VHS-demoed. [DOC] document the new guards + `withCellDimensions()`.

### candy-mouse
- [BUG] `Scan.php:109` — duplicate zone id silently last-write-wins (merges two zones into one wrong bbox)
  → reject/warn on duplicate open id.
- [PERF] `Scan.php:74` `strpos(CLOSE,$i+3)` rescans to EOS ⇒ O(n²) → precompute a close-sentinel map;
  `Scanner.php:85-93` `hit()` O(n) per call → add a grid-bucket index.
- [SEC] unbounded input via `Mark::wrap()` on reflected text (mild DoS w/ the O(n²)) → length guard; add the
  unescaped-`id` sentinel guard in `Mark::wrap()` (W3).
- [DEDUP] `Zone.php`/`Scanner.php` whole-lib bubblezone overlap with candy-zone (W3); `MouseAction.php:28-30`
  button constants copied from candy-input → import/share.
- [DEADCODE] `composer.json:30-52` unused candy-ansi/async/input/pty path-repos → prune (W7).
- [FEAT] single `Scroll` case, no up/down → split direction or document the overload.
- [TEST] add many-unmatched-open (O(n²)) + duplicate-open-id regressions.
- [DOC] flag the `MouseEvent` name collision with candy-input, the decoded-vs-raw scope, and the empty
  `lang/en.php`; add a deliberate VHS-exemption note.

### candy-palette
- [PERF] `Probe.php:126` — blocking `shell_exec(infocmp)` per resolve, result uncached → memoize;
  `StandardColors.php:102-117` 16 eager Color instances at file-load → lazy-init.
- [DEADCODE] `composer.json:20` requires candy-sprinkles, unused in `src/` → drop (or wire the intended
  integration). (W7)
- [FEAT] add named-hex palette constants (`Palettes::DRACULA`, …) as the W5 SSOT source; consider
  HSL/HSV/mix if consumers need it.
- [TEST] `AsyncProbe` (full ReactPHP flow) has no test; `infocmpUpgrade` per-branch untested → add.
- [DOC] README omits AsyncProbe/DetectionChain/`Probe\*`; three parallel detection entry points unexplained
  → document + precedence.

### candy-pty
- [SEC] `PosixPtySystem.php:67,102` — unchecked `fcntl(F_SETFD, FD_CLOEXEC)` return (silent failure
  reintroduces the SIGHUP bug) → check rc, throw/warn. (Part B)
- [SEC] `Spawn.php:62-66` — slave PTY opened by path 3× (TOCTOU) → dup the held slave fd for all three
  stdio slots.
- [SEC] `Libc.php:80-82` — `SUGARCRAFT_LIBC` loads an arbitrary `.so` (unauth code-exec) → trusted-env-only
  note + opt-in/allowlist gate.
- [BUG] `PosixTermios.php:90-97` — `restore()` silent no-op when no snapshot → throw when `$original` null
  and a restore is expected; `Spawn.php:9-11` misleading `@deprecated` → correct.
- [PERF] `PosixPump.php:184-196` — `flushMaster()` `usleep(20_000)` even when empty → check-empty first.
- [TEST] `requirePtySyscalls()` duplicated across 41 files → one shared trait/base TestCase; the
  `@deprecated` tag fires nothing → trigger+test or drop.
- [DOC] document the unchecked-rc caveat, Spawn deprecation nuance, a `ControllingTerminal::claim()` FFI
  example. VHS exemption correct.

### candy-query
- [SEC] `AsyncCachingServerContext.php:75,81,90` — `plugins()/version()/flavor()/versionString()` delegate
  straight to synchronous PDO, bypassing `AdminQueryCache` (ServerStatus/Dashboard/PerfSchema `view()`
  paths block the React loop) → route through the cache like the variables calls. (Part B)
- [BUG] `ConnectionActions.php:107` — `WHERE HOST='%' AND USER='%'` literal-equals only matches a row named
  "%" → fix to catch-all-actor semantics.
- [BUG] `CsvExporter.php`/`SqlExporter.php` hardcode `Flavor::MySQL` quoting despite driver-neutral typing
  (Postgres ⇒ invalid backticks) → derive `Flavor` from `$this->db`.
- [SEC] `QueryLogger.php:26-40` — retains raw SQL incl. `IDENTIFIED BY '<secret>'` on the Debug pane → add
  credential redaction.
- [FEAT] no transaction contract; history/favorites in-memory only; `sqlsrv` DSN documented but rejected →
  add transactions; persist history in `bin/candy-query`; implement or remove sqlsrv.
- [PERF] exports read whole result set into memory → chunk/stream.
- [TEST] no regression asserting admin pages avoid blocking `plugins()/version()`; exporter non-MySQL flavor
  untested → add. [VHS] whole Admin surface undemoed → add tapes. [DOC] fix sqlsrv/exporter/favorites claims.

### candy-serve
- [SEC] `Server.php:794-796` — `X-CandyServe-User` bare lookup ⇒ unauthenticated impersonation → require a
  signed token/shared-secret or a trusted-proxy allowlist + strip client copies. (Part B)
- [BUG/SEC] `GitDaemon.php:765-838` — `handleReceivePack()` updates refs without reading/unpacking the
  packfile (repo corruption) → run `git receive-pack`/`index-pack`/`unpack-objects` before ref update
  (mirror the HTTP path).
- [SEC] `SSHServer.php:152-169` — `authenticate()` returns true on null/empty key → deny/require key. (W10)
- [SEC] `AccessControl.php:78-81` hardcoded `allowAnonymousRead()=true` → config-driven; `StatsServer` no
  auth → loopback default/auth; `GitDaemon.php:316-377` unconditional oldest-connection eviction (kills
  transfers), no per-IP cap → per-IP caps, don't evict active transfers.
- [BUG] `Config.php:40-41,163-164` — `tlsKeyPath`/`tlsCertPath` parsed but unused → wire once a listener
  exists or mark reserved.
- [FEAT] `bin/soft-serve:123-253` — only GitDaemon binds; SSH/HTTP/StatsServer never listen; user-add never
  persists → wire listeners (route SSH via candy-wish, W10) + persistence.
- [PERF] `Server.php:641`/GitDaemon `sendPack` — packfile fully buffered despite `chunked` → true streaming.
- [DEDUP] `SSHServer.php` reimplements candy-wish → refactor to a candy-wish middleware. (W10)
- [TEST] no bin e2e, no post-push object-retrievability assertion (would catch the unpack bug), no
  attacker-header test, no wire-parser fuzz → add. [DOC] correct overstated SSH/HTTP/TUI + trust-bypass.

### candy-shell
- [SEC] `FormatCommand.php:97-104` — `{{VAR}}` expands any env var (secret exfil) → gate behind
  `--allow-env`/allowlist. (Part B)
- [SEC/BUG] `LogCommand.php:50-53` — unguarded `sprintf` ⇒ uncaught `ValueError` → try/catch.
- [BUG] `SpinCommand.php:48` (+all) — `--timeout` declared, never enforced → wire it (kill child on spin
  timeout; idle-abort interactive Models).
- [PERF] `Application.php:110-155` — reflects Symfony's private `ArgvInput::$tokens` → guard with
  `property_exists()` + a test that fails loudly on a Symfony bump.
- [TEST] `SpinCommandTest` (37 lines) thinnest yet most security-relevant → FakeProcess cases (timeout
  no-op, align, show-stdout/stderr, nonzero-exit); `LogCommand` malformed-format regression.
- [DOC] README cites a missing `AUDIT_2026_05_06.md`; "Porting from gum" implies `--timeout` honored;
  template env-exposure undocumented → fix. VHS complete.

### candy-shine
- [SEC/PERF] `SyntaxHighlighter.php:142,162` — nested negative-lookahead alternations via `preg_match_all`,
  no length guard (ReDoS) → input-length cap + backtracking-safe patterns. (Part B/W9)
- [SEC] `Renderer.php:255-258,567-585` — `withSanitize(false)` disables stripping globally incl. raw HTML →
  per-node-kind granularity; `:304-306` emoji expansion before `stripControls` → reorder after sanitize.
- [FEAT] `SyntaxHighlighter.php:18,89` — final/static, 8 langs hardcoded, no injection seam → pluggable
  `Highlighter` interface (W9); `Theme`/`Renderer` — no light() from env, no width autodetect, no footnotes
  → add or document vs glamour.
- [PERF] `Renderer.php:278-300` — `copy()` rebuilds the CommonMark parser per `with*()` → build lazily once
  per render.
- [TEST] no ReDoS/large-input test; no OSC-8 8-bit-ST boundary test → add.
- [DOC] README omits `withSanitize()` + its tradeoff and the wired-extension list; CALIBER lacks
  ReDoS/global-sanitize notes → document. [VHS] add custom-renderer + stdin tapes.

### candy-sprinkles
- [DEDUP] `Theme.php:91-109` — canonical `dracula()` vs 7 copies → drive convergence via candy-palette
  constants; add the `RegionBridge` converter for W12. (W5/W12)
- [BUG] `CALIBER_LEARNINGS.md:6` falsely says primary/secondary + accent/muted are aliases in all themes →
  correct; `Style.php:757-767` `inherit()` docblock contradicts the `self(...)` call → fix doc.
- [PERF] `Style.php:937-1079` — `render()`/`buildContentSgr`/`buildBorderSgr` recompute per call in
  per-frame loops → cache content-independent SGR per immutable instance; `:976-980` per-line
  `Width::string()` computed twice → once.
- [FEAT] gradient limited to borders → add `Style::foregroundBlend()` per-char text gradient.
- [TEST] Layout value objects, Listing/Tree enumerators, Table/Data, Border/TitleAnchor — no direct tests →
  add.
- [VHS] no Theme/Markup/StyleParser/Hsl/underline-style tapes → add (Theme demo high-value given the SSOT
  claim). [DOC] reconcile `inherit()` list + the SSOT claim (sugar-dash still hand-rolls its Theme).

### candy-testing
- [SEC] `Snapshot/GoldenFile.php:67-70` — `resolve()` no traversal guard → reject `..`/realpath-contain. (W6)
- [BUG] `TestResult.php:37-77` — cmd-assertions throw `RuntimeException` not `Assert::fail()` → route through
  Assert + test. (W6)
- [DEDUP/FEAT] promote `TemporaryDirectoryTrait` to `src/Concerns` (162 hand-rolled sites); add
  `ProgramSimulator::for(Model)` overload (80 hand-rolled KeyMsg sites). (W6)
- [DEADCODE] `TapeRecorder` zero consumers → wire into the vhs flow or remove; `Assertions::assertCellGrid`
  zero consumers → adopt in candy-vt/game-board cell tests or document.
- [FEAT] add a `subscriptions()`-behavior inspector + the W13 `view()`-idempotence helper.
- [TEST] `assertCmd*` untested; `applyMsg()` maxCycles overflow, `resolve()` traversal untested → add.
- [DOC] README omits TapeRecorder + TestResult helpers + the capture-vs-execute toggle → document. VHS
  exempt.

### candy-tetris
- [DEADCODE] `Renderer.php:184-202` — `ghost()` dead, `block()` builds raw SGR duplicating Buffer+ghostStyle
  → remove `ghost()`, migrate `renderMini()` off `block()`.
- [BUG] `bin/tetris` calls `Game::start()` not `startWithLockDelay()` (lock-delay dead in the binary) →
  wire it or document off. (W15)
- [FEAT] no high-score persistence → add via W8 `AtomicJsonFile`; make next-queue depth configurable.
- [TEST] top-out branch never driven through the real `lockAndSpawn`; no end-to-end line-clear→Score;
  kick-selection only hit on empty board → add.
- [DOC] controls table omits `c` (hold); hold-demo.gif not embedded; lock-delay unmentioned → add. VHS
  complete.

### candy-vcr
- [BUG] `Compiler.php:141` — `Output <path>` compiles to null and no render command reads it (masked by
  convention) → carry it into the Cassette header, prefer it over the default (explicit `--output` wins),
  traversal-confined. (Part C)
- [BUG] `Compiler.php:281-304` — out-of-base/unresolvable `Source` silently skipped → raise `ParseError`
  (or warn under non-strict).
- [SEC] `RecordCommand` `filteredHostEnv('')` — empty regex records secrets → require `--env-all` + warn
  with captured var names.
- [PERF] `RenderBatchCommand.php:106-129` — 291 tapes render sequentially (~6min/tape) → `--jobs N`
  subprocess pool; profile the two-pass ffmpeg palette graph; `FrameStream.php:74-79` skip idle grid copies;
  eliminate the CFR frame double-copy; `GdRasterizer.php:270-273` memoize per-char width.
- [FEAT] missing directives (`Require`, Copy/Paste, Right/Middle Click, `Alt+`, many `Set` keys), only 5
  themes vs ~30, GIF-only output → implement low-cost directives + `Require`, port the theme table,
  dispatch encoder on output extension, make unknown directives a `--strict` error.
- [TEST] add a Compiler+TapeToGif end-to-end test that `Output` controls the file location + a
  traversal-rejection case; add a non-dry-run `RenderTapeCommand` test.
- [DOC] README:555 wrongly says `Output` is used by the render step; CALIBER:337-339 stale per-frame ffmpeg
  note → correct; add a "not yet implemented" section.

### candy-vt
- [BUG] `Parser.php:198-205` (line 200) — `start()` clears `stringBuffer` (regressed candy-ansi bug); an
  in-flight SOS/PM/APC with an 8-bit C1 re-introducer is destroyed → delete the line + regression test,
  same PR. (Part C / W1.1)
- [SEC] `Parser.php:25` — `MAX_STRING_BYTES = 1 MiB` (16× candy-ansi) → reduce to 64 KiB or document. (W1.2)
- [DEDUP] `CsiHandlerImpl.php` (387-line fork) + unused `candy-ansi` path-repo (`composer.json:97`) → W1.3;
  `Buffer/Buffer.php`+`Cell.php`+`Cell/Cell.php` + unused `candy-buffer` require (`:29`) → adopt or drop. (W1.4/W7)
- [PERF] `Parser.php:52-58,215` byte-at-a-time `feed()`/`put()` → bulk-scan runs with `strcspn`/`strpos`;
  `Buffer/Buffer.php:29-38` per-cell `Cell::empty()` on resize → `array_fill` one shared empty cell.
- [DEADCODE] `Terminal.php` unread `$scrollbackSize` → thread into `Scrollback` or delete; remove dead test
  helpers flagged in phpstan-baseline.
- [TEST] no case interleaves a second string-introducer mid-collection; tautological `assertSame`/`assertTrue`
  → add `testStringInterruptedByC1IntroducerStillDispatchesPayload` + a byte-verbatim fuzzer fixture.
- [DOC] document Sixel/Kitty/iTerm2 + mouse-reporting as non-goals; note the CsiHandlerImpl fork; record
  candy-ansi's 2026-05-30 `start()` lesson.

### candy-wish
- [BUG] `examples/hello-server.php:26-42` — `Banner::handle` doesn't match `Middleware::handle(Context,
  Session, callable)` ⇒ fatals → fix signature + `$next($ctx,$s)`; add `.vhs/hello-server.tape`. (Part C)
- [SEC] `RateLimit.php:60-65` fails open silently → log + `failClosed` flag; throttling per-IP only → add
  per-username + auth-failure penalty API + document ordering; `:108` state file umask-perms → chmod 0600.
- [SEC] `PasswordAuth.php`/`InProcessTransport.php:269-275` — `SSH_PASSWORD` leaks to a child if a spawner
  precedes PasswordAuth → scrub in the transport preamble regardless of order; `Auth.php:72-91` trusts
  forgeable fingerprint headers → trust-boundary warning + optional validator hook.
- [DEADCODE] `AuthMethods::fromContext()` unused, RFC-4252 semantics impossible post-ForceCommand → relabel
  informational or delete.
- [FEAT] no session-transcript middleware → add a `Transcript` tee for audit replay.
- [TEST] Channel/Msg DTOs, chained Auth-stack precedence, concurrent `RateLimit` flock, `SftpStub` → add.
  [VHS] `spawn-program.php` no tape → add.
- [DOC] README must state Auth middleware are post-hoc allowlists running AFTER sshd (real boundary is sshd
  config); `ext-ssh2` "Ssh2 client middleware" references a nonexistent class → mark planned/remove.

### candy-zone
- [SEC] `Manager.php:109-118` — `mark()` embeds unescaped id between APC delimiters; a crafted id injects
  bytes → validate `^[A-Za-z0-9._:-]+$` + land the sibling fix in `Mouse\Mark::wrap()`. (W3)
- [DEDUP] `Manager.php`/`Zone.php`/`ClickCounter.php` — parallel bubblezone port incompatible with
  candy-mouse → delegate to candy-mouse primitives; `Zone\Zone` becomes a BC adapter. (W3)
- [PERF] `Manager.php:294-311` — `anyInBounds()` O(n) per event, run twice via hover+drag sharing a manager
  → cache per (x,y,zone-generation) within a tick + document the "n<100" caveat.
- [TEST] `close()` mid-drag/hover/click-streak, overlapping-non-nested zones, adversarial ids, Scroll via
  DragTracker, ClickCounter with vanishing zones → add.
- [DOC] README never mentions candy-mouse despite 3 shared consumers → add a "how this differs" (interim)
  section + the id-format constraint; add a CALIBER entry on the overlap.

### honey-bounce
- [BUG] `SpringCollection.php:30,41,135` — `add()`/`remove()`/`setTarget()` mutate in place while `tick()`
  clones → convert to `withSpring()`/`without()`/`withTarget()` via `mutate()` (deprecated void shims one
  release). (W13)
- [PERF] `SpringChain.php:84-91` — `tick()` copies the whole `$stages` array when one changes → clone once,
  replace one index.
- [DEADCODE] `Spring.php:25` `SETTLING_THRESHOLD` unused while SpringChain uses a private `0.0005` → use in a
  shared `Spring::settled()` or remove + document.
- [TEST] `mass<=0` throw untested; damping-branch EPSILON seams untested → add boundary tests.
- [VHS] orphan `spring.gif` with no `spring.tape`; `particle.php` has no tape/README row → add.
- [DOC] README omits the `mass<=0` throw, the two settling thresholds, and SpringCollection's (current)
  mutability exception → document.

### honey-flap
- [DEADCODE] `Game.php:165-176` — private `persistHighScores()` never called, duplicates the inline closure
  at `:216-228` → have `update()` call it + test. (Part C)
- [FEAT] `Bird.php:37-53` — fall velocity never clamped though `Projectile::TERMINAL_GRAVITY` exists →
  construct with terminal gravity; `withHighScore()` unbounded → keep top 10; verify pipe width/speed ramp
  vs upstream.
- [TEST] no test drives a pipe collision, top-wall crash, or score increment; `PipeGenerator` gap bounds
  only `rand=0` → add.
- [DOC] README omits high-score persistence, top-wall rule, and `Bird` tuning constants → add.

### sugar-bits
- [PERF] `Table/Table.php:165-166,412-413,578-580` — `view()`/`selectedRow()` re-run sort+filter per call →
  memoize the projection keyed on (rows, sortState, filter).
- [DEDUP] `tests/{TextInput,…,Spinner}/` — 2,867 LOC of namespace-only copies of candy-forms suites →
  delete the 8 dirs, add one `AliasesTest.php`. (W17)
- [VHS] `examples/tabs.php` the only first-party widget without a tape → add `tabs.tape`.
- [DOC] no cross-reference for the two `Table` classes (bubbles vs Evertras); the 8 `@deprecated` aliases
  have no sunset timeline → add disambiguation + "removed in v2.0" note.

### sugar-boxer
- [BUG] `Node.php:265-314` — `totalWidth()`/`totalHeight()` omit `$margin`, so flex under-counts margined
  children → add margin components + a margin+flex regression test.
- [BUG] `Node.php:68,329` — no clamping for negative `withMinWidth`/`withPadding`/`withSpacing` → clamp to
  `max(0,...)` like `withFlex()` + spec with tests.
- [PERF] `SugarBoxer.php:31,410` — `sgrPrefixCache` keyed by `spl_object_id`, never evicts, not cleared in
  `resetPreviousFrame()` → key by Style value hash / LRU + clear; `:103` reuse the grid buffer across
  same-dim renders; memoize `totalWidth/Height` on the immutable Node.
- [DEDUP] `SugarBoxer.php:846-938` — hand-rolled solver duplicates candy-layout (dep unrequired) → map Node
  constraints to candy-layout after golden parity (W12); `:759-802` `drawBorder()` reimplements
  candy-sprinkles Style border+title → delegate (unlocks 6-anchor titles).
- [FEAT] `Node.php:47` leaves carry only `string $content`; upstream forwards `Msg` to an embedded model
  per leaf with address lookup + focus → add `withModel`/`withId`/`find`/focused-border (largest parity gap;
  MATCHUPS.md:57 🟢 overstates).
- [TEST] maxWidth/maxHeight clamps, nested `noBorder()`, top-level w/h<=0, cache lifetime → add.
- [VHS] no `withFlex`/`withStyle`/`withTitle` demo → add `flex`/style examples + tapes.
- [DOC] README badge `sugarcore/sugar-boxer` → fix org; surface the "reuse same instance for diff" +
  `withBorder(false)` footguns from CALIBER.

### sugar-calendar
- [SEC] `Navigation.php:39` — `gridIndexToDate()` interpolates unvalidated `$month`/`$year` (month 13 ⇒
  silent shift) → validate ranges + `createFromFormat('Y-m-d', sprintf(...))`. (Part B)
- [BUG] `DatePicker.php:498,524-525` — `View()` mutates cache fields (breaks immutability; cache only helps
  the default path) → delete the cache, render O(42) unconditionally. (W13)
- [FEAT] no timezone handling (`isToday`/range off by a day) → `withTimezone()`; hardcoded Sunday-first →
  `withWeekStart()`; only `en` locale → add the LOCALES set.
- [DEDUP] `Navigation` duplicates DatePicker grid math but is never called → DatePicker delegates (or fold
  Navigation in); consolidate `firstDayOffset()`.
- [TEST] `isoWeekNumber()`, leap-year Feb 29, DST `isToday`, ISO week boundaries, `DateRange` end<start,
  March-31→Feb nav — zero coverage → add.
- [DOC] README omits range mode, `EventStore`/`Model`, `withToday()`, `View()` params, and the styling
  setters → document; clarify `EventStore` records UI events, not appointments.

### sugar-charts
- [BUG] no `is_finite()`/`is_nan()` guards anywhere (`BarChart:325`, `LineChart:409`, `Scatter:204`,
  `Heatmap:266`, `NiceScale:35`) — NaN defeats divide-by-zero checks, indexes palettes out of range → a
  shared `Finite` guard at every ingestion point + NaN/INF tests. (Part B-adjacent)
- [PERF] `Streamline.php:42-51,97-104` — `push()` re-slices + `view()` rebuilds a full chart per frame →
  ring buffer + reuse the configured chart.
- [FEAT] Scatter single global rune (no per-series); BarChart single value (no grouped/stacked); LineChart
  no downsample despite `Resample`/`BucketByTime` → add multi-dataset/grouped/downsample.
- [TEST] no NAN/INF test per chart; `ChartExtras` no unit file → add.
- [VHS] `animated_line.php` no tape + missing README row; no multi-series/theme/braille/Streamline demo →
  add.
- [DOC] README:210 says LineChart per-dataset styling "pending" but it's implemented → reconcile; add the
  untrusted-numeric-input note; disambiguate Heatmap's color-bar legend from series legends.

### sugar-crumbs
- [SEC] `NavStack.php:182-191`/`Breadcrumb.php:156-215` — titles (from `Shell::pushDirectory`/`Url::parse`)
  rendered with no C0/ANSI stripping → route through candy-core `Sanitize` + injection test. (Part B/W2)
- [BUG] `NavStack.php:236-238` — `filter()` `(string)$item->data` fatals on array data (the example pattern)
  → guard `is_scalar()||Stringable` + test. (Part C)
- [BUG] `Breadcrumb.php:226-244` — `truncate()` keeps the last title unconditionally, busting `setMaxWidth()`
  → ellipsis-truncate the final segment + test. (Part C)
- [BUG] `Escape.php:12` — `Escape::title()` hardcodes `' > '` vs the `' › '` default and `render()` never
  calls Escape → add a `$separator` param + wire/document.
- [DEDUP] `Breadcrumb.php:101-110` + candy-zone require — `withZoneManager()` never calls a Manager method →
  delete the method + 4 tests + import + candy-zone require/path-repo. (W3.1)
- [FEAT] `hit()` returns a Zone but click→navigate never landed → `NavStack::handleClick(Zone)` parsing
  `crumb-N`→`popTo(N)` + e2e test.
- [PERF] `truncate()` re-`implode`+re-measure per iteration (O(n²)) → accumulate widths incrementally.
- [TEST] replace the `assertTrue(true)` placeholder at `BreadcrumbTest.php:268-291` with a real
  zone-count-equals-visible assertion.
- [VHS] no tape exercises Scanner/`hit()` → add `examples/clickable.php` + tape once `handleClick()` lands.
- [DOC] `CALIBER_LEARNINGS.md:7-32` documents the retired candy-zone design as current → rewrite; README
  omits `Escape`/`Url`/`Closable`/`viewHtml()` → document.

### sugar-crush
- [SEC] `Backend/EngineBackend.php:84` — `complete()` falls back to an empty `HookManager`, so skipping
  `withHooks()` runs Bash/Edit/Write unguarded → make the fallback `registerBuiltIns()` (safe-by-default)
  + a `withoutHooks()` escape hatch + a default-deny `rm -rf` test. (Part B)
- [SEC] `ConfirmRemoveHook.php:33` regex misses `rm --recursive`, `x=rf; rm -$x`, `find -delete`,
  `shred`/`dd` → extend patterns + document as heuristic + bypass tests; `Bash.php:36-48` no `PathJail` →
  document asymmetry + opt-in escape-deny hook; `ProtectFilesHook.php:14-20` fixed list → configurable;
  `Session.php`/`SessionStore` transcripts world-readable → chmod 0600.
- [BUG] `Tools/PathJail.php:9-36` — `resolve()` rejects a path equal to the jail root (off-by-one) → accept
  `$resolved===$rootReal` + `PathJailTest`.
- [DEADCODE] CALIBER documents pre-merge lister built-ins absent from README → confirm the ToolRegistry
  slash-command layer is live or delete the entries.
- [TEST] no `PathJailTest`; `McpServer` base uncovered → add.
- [VHS] single `chat.tape` → add `tools.tape` driving a hook-deny turn.
- [DOC] `PROJECT_NAMES.md:126` stale `CandyCrush` row → update to `SugarCrush`; README lacks a security-model
  paragraph → add.

### sugar-dash
- [DEDUP] `Output/Sanitize.php:33-99` — `untrusted()`'s C1-aware scan is the best-of-four → hoist into
  candy-core `Sanitize::untrusted()`, delegate (call sites unchanged). (W2)
- [DEDUP] `Layout/FocusManager.php:12-156` — parallels candy-focus `FocusRing`, zero src consumers, no test
  → recompose as a persistence wrapper over an internal `FocusRing` (add `FocusManagerTest` first as a net).
- [DEDUP] `Foundation/Theme.php:59-73` — independent `dracula()` hexes → read candy-palette constants; keep
  the API per CALIBER:40. (W5); `Components/StatusBar` → W16; `Components/Toast/*` → W18.
- [DEADCODE] `Events/MouseEvent.php` consumed nowhere (whole `Events/` untested) → wire mouse events
  (prereq for drag-resize) or delete.
- [FEAT] drag-to-resize absent → decide scope (non-goal in README, or build on candy-mouse zones).
- [PERF] `examples/dashboard-live.php:332-363` — `view()` rebuilds the whole Boxer tree per Msg though
  `msgToAddress()` knows the target → memoize per-module output, invalidate only the target.
- [TEST] `FocusManager` wraparound + persistence; Boxer/StackedGrid persistence round-trips; `Events/*`
  zero tests → add.
- [DOC] `media/icons/sugar-dash.png` missing (W14); README lacks a "Design notes" section linking the
  intentional FocusManager/Sanitize/Theme divergences → add.

### sugar-gallery
- [SEC] `PosterCard.php:131-136` — `withStyledTitle()` bypasses `stripC0()` silently → docblock + README
  trust-boundary warning (plain `title` is the sanitized path). (Part B)
- [PERF] `PosterGrid.php:35,97-119` — sparse `$items` map only grows (undermines 50k-item scaling) → add
  `withoutItemsOutside(range)` + memory-bound test; `box()`/`posterRows()`/`fitWidth()` recompute
  `Width::string()` per line per render → cache fitted lines per card.
- [FEAT] `visibleRange()` has no fetch-dedup helper → add `needsFetch(?range): bool`.
- [TEST] no end-to-end paging loop; `testRenderMarksZones` uses an exact 8/4 multiple (blank-filler
  invariant unverified); `stripC0()` only tested on bare `\e[2J`+BEL → add partial-last-row + parameterized-CSI
  tests.
- [VHS] no `.vhs/` dir, absent from vhs.yml (visual lib) → add `examples/` + `grid`/`rail` tapes + matrix.
- [DOC] `media/icons/sugar-gallery.png` (+docs copy) missing → add. (W14)

### sugar-glow
- [SEC] `RenderCommand.php:72-172` — `--theme-config`/file/stdin slurped uncapped before decode → max-bytes
  cap. (Part B)
- [DEDUP] `GlamourTheme.php` + `Highlighter/*` — dead shadow stack duplicating candy-shine → after adding a
  candy-shine ESC-strip test, delete classes + their tests. (W11)
- [DEADCODE] `Pager.php` unused (GlowModel wraps sugar-bits Viewport) → delete `Pager.php` + `PagerTest.php`
  (or wire for large-file streaming).
- [FEAT] `FileWatcher` exists but no `--watch` flag → add; no dir-browse/TOC/`/`-search/config vs upstream →
  add at least `/` search + heading-jump.
- [TEST] pager path has no scripted-KeyMsg e2e; no malformed-JSON test on the live `--theme-config` path →
  add.
- [VHS] no theme-config/`--no-hyperlinks`/`--width` tape → add after the dead-code purge.
- [DOC] README:75-131 + CALIBER:8 document `GlamourTheme` as live → purge; add a "known gaps vs glow"
  section.

### sugar-post
- [SEC] `SmtpTransport.php:96-144` — port-465 dials plaintext then issues `STARTTLS` (fails SMTPS, leaks
  pre-TLS bytes) → enable verified crypto at connect (`tls://`/`stream_socket_enable_crypto` before EHLO),
  STARTTLS only when advertised and not already encrypted, + loopback test. (Part B/C)
- [SEC] opportunistic STARTTLS (downgrade-strippable) → `requireTls` flag throwing if no TLS;
  `:23-37` plaintext password → `__debugInfo()` mask.
- [BUG] `Attachment.php:34-84` — `@`-suppressed read failures ⇒ silent 0-byte attachment → throw at
  `fromPath()`.
- [PERF] `:10-11` unused React `Loop`/`LoopInterface` imports while `send()` blocks the loop → remove
  imports + document the blocking contract; `ResendTransport.php:40-49` no `CURLOPT_CONNECTTIMEOUT`/explicit
  verify → add.
- [FEAT] only `AUTH LOGIN` → add `PLAIN`/`XOAUTH2`; no attachment size pre-check → warn/throw.
- [TEST] the whole `send()` dialogue has zero socket-level tests → add a fake-SMTP loopback (success,
  auth-fail, STARTTLS-fail, 465); ResendTransport 4xx/5xx/timeout branches → add.
- [VHS] add attachments/html-email/pipeline/smtp tapes. [DOC] correct the Gmail example + "TLS on 465"
  wording; note retry is caller responsibility.

### sugar-prompt
- (fuzzy duplication: **RESOLVED** — genuine `class_alias` shims over candy-forms/candy-fuzzy, guarded by
  `AliasResolutionTest`; no code work.)
- [BUG] README:207-224 — documents nonexistent `FuzzyMatcher::score()`/`match(string,array)` (real:
  `match(string,string): ?MatchResult`/`matchAll()`) ⇒ example fatals → rewrite around `match()`/`matchAll()`.
- [TEST] `Spinner.php:20-33` — Windows/no-pcntl + non-TTY branches unverified → inject the fork/TTY
  predicates + test both.
- [VHS] `Spinner` (the only non-shim class) has no tape → add `spinner.tape`.
- [DOC] `AliasResolutionTest.php:138` stale comment claims a "own FilePicker" (it's an alias) → fix; note
  Spinner is the single independent class.

### sugar-readline
- [SEC] `History/FileHistory.php:127-151,243-246` — predictable temp file, `chmod(0600)` **after** writing
  content (world-readable TOCTOU + symlink-preplant) → `tempnam()` + `chmod(0600)` before writing (or
  `umask(0177)`). (Part B)
- [BUG] `TextPrompt.php:286-295`/`EmacsMode.php:88-155` — Ctrl+U/K/W/Alt+D destroy text (no kill-ring, no
  Ctrl+Y) → add a `KillRing` + yank/cycle bindings.
- [FEAT] `ViMode.php:335-387` `yy`/`yiw`/`ya(` are no-ops (no register, no `p`/`P`) → capture into the
  kill-ring + paste bindings; static-array completion only → `withCompletionCallback(callable)`.
- [PERF] `FileHistory.php:125-152` rewrites the whole file per push → `LOCK_EX` append fast-path;
  `:165-231` `load()` slurps + `in_array` dedup (O(n²)) → tail-read + hash set.
- [DEDUP] four copies of `isWordChar()`/word-motion (candy-forms' is ASCII-only, diverging from three
  Unicode copies) → hoist one `\p{L}` helper beside candy-forms' `VimKeyHandler` and delegate all four.
- [TEST] `runLoop()` mouse/focus/paste dispatch + TTY guard untested; multi-instance history append
  untested → add.
- [VHS] `examples/interactive.php` no tape; no vi/emacs/Ctrl-R demo → add + register.
- [DOC] README "Known limitations" (destructive kills, Textarea no vi/emacs, `FileHistory` O(file) cost,
  history-path perms) + surface `withAutoSuggest()` → add.

### sugar-reel
- [PERF] `Player.php:653-745` — `frameToBuffer()` per-cell `withCellAt()` ⇒ candy-buffer COW duplicates the
  grid every call (O(cells²)/frame) → accumulate a grid array and call `Buffer::fromGrid()` once + assert
  byte-identical output. (Part C-adjacent perf)
- [PERF] `Decode/RgbFrame.php:62-85` — `toGd()` per-pixel loop + PNG re-encode per frame → cache the GD
  conversion per frame or a packed-blit fast path.
- [DEDUP] `Player.php:672-721` vs `Render/{HalfBlock,QuarterBlock}Renderer` — inline duplicate quantization
  → after the `fromGrid` fix, benchmark routing through the Mosaic renderers, delete the inline copy or
  document why both exist.
- [DEADCODE] `Lang.php`+`lang/en.php` — full i18n scaffolding, zero `Lang::t()` call sites → wire or delete.
- [SEC] `Reel.php:90-97` `openUrl()` only `^https?://` (SSRF via ffmpeg) → optional host-allowlist + warning;
  `Subtitle/WebVtt.php:43-62` no cue-count cap → cap.
- [FEAT] `Sync.php:51-54` hardcoded 2-frame skip → expose as a parameter.
- [TEST] `Cue::contains()` half-open interval no direct test; `RendererFactory` auto-ladder not asserted
  rung-by-rung → add.
- [VHS] no `m` mode-cycle/seek/speed demo → add `modes.tape`.
- [DOC] README:155 calls audio "master clock" but code paces off wall clock → correct + add Sync/GIF-cost/
  SSRF notes.

### sugar-skate
- [BUG] `bin/skate:87-92` — `list` calls nonexistent `Store::sanitizeForTty()` (fatal) → implement it
  (C0/ESC strip via candy-core Sanitize; `<binary N bytes>` placeholder). (Part C/W15)
- [BUG] `bin/skate:72` — `get`-miss calls private `Store::suggestSimilar()` (fatal, and duplicates the
  suggestion `Store::get()` already emits) → delete the redundant block, branch on `entry()===null`.
- [BUG] `Import/JsonImporter.php:67` — `json_decode` no `is_array()` guard (scalar JSON ⇒ foreach warnings)
  → add the guard (+ `YamlImporter`). (W8)
- [BUG] README Quick Start — `set('token','ghp_xxxx','passwords')` passes a db name into `bool $binary` →
  correct to `set('token@passwords','ghp_xxxx')`.
- [FEAT] `Cli/ExportCommand.php:48-81` single-db only → `--all` export.
- [TEST] `bin/skate` zero e2e coverage (why both fatals shipped) → add a `proc_open` smoke test over
  set/get/get-miss/list/delete/import/export against a temp `--data-dir`; `Entry::fromRow()` bad-date →
  wrap+test; migration/`listDatabases()` exclusion → test.
- [VHS] no import/export/TTL tape → add after the CLI is fixed. [DOC] add a CALIBER note that `bin/skate`
  is untested surface.

### sugar-spark
- [BUG] `StreamingInspector.php:69` — `finish()` checks `State::Escape` **after** `flush()` (always Ground)
  ⇒ trailing bare ESC silently dropped → capture `$stateBeforeFlush` before `flush()`. (Part C)
- [BUG] `StreamingInspector.php:70,77-80` — writes private `AnsiHandler::segments[]` and calls protected
  `getSs3Intermediate()` from a foreign class ⇒ fatal `Error` on dangling SS3 → add public
  `pushSegment()`/`ss3Intermediate()` (or an `AnsiHandler::finishPending()`).
- [SEC] `Inspector.php:358-395` — `describeDcs()` interpolates `$payload` raw (OSC/APC go through
  `sanitizeLabelBytes()`) → sanitize DCS too; `bin/sugarspark:21-26` `@file_get_contents($argv[1])` honors
  stream wrappers → `is_file()` guard.
- [PERF] `AnsiHandler.php:159` — segments/text accumulate unbounded unless drained → document the drain
  contract + optional cap.
- [TEST] `StreamingInspectorTest` never feeds a trailing bare ESC or dangling SS3 → add
  `testFinishEmitsTrailingBareEsc` + `testFinishEmitsDanglingSs3WithoutError`.
- [DOC] `CALIBER_LEARNINGS.md` missing → create (flush-resets-state + AnsiHandler-visibility gotchas);
  README omits the `StreamingInspector` `feed()`/`finish()` contract → add.

### sugar-stash
- [BUG] `Git.php:34` — `substr($line,3)` breaks renames (`"old -> new"`), breaking discard/stage/diff → detect
  `R`/`C`, split on `" -> "`, carry old+new. (Part C)
- [BUG] `bin/sugar-stash:25` — `is_dir(".git")` rejects linked worktrees → `git rev-parse --absolute-git-dir`
  probe (as CALIBER already prescribes for `rebaseInProgress()`).
- [SEC] `Git.php:207-212` — `worktreeAdd($path,...)` only leading-dash guarded → reject `..` + resolve
  against a realpath'd parent. (Part B)
- [BUG] no `WindowSizeMsg` handling; pane widths hardcoded (36/31/32/26) → store size from `WindowSizeMsg`,
  derive geometry.
- [PERF] `Renderer.php:120-164` renders every entry (overflows on big repos) → per-pane scroll + windowing;
  `Git.php:243-263` `proc_open` has no timeout (UI hang) → stream_select timeout + kill; `App.php` every key
  triggers 3 git subprocesses → dirty-flag per pane.
- [FEAT] `GitDriver` — no push/pull/fetch/tags/submodules → add fetch/pull/push (+tags), defer the rest.
- [TEST] real `Git` parsing essentially untested (why the rename bug shipped) → temp-repo integration tests
  for status(+rename)/branches/log/mutators; `bin` CLI smoke test from a linked worktree → add.
- [VHS] only play/stage tapes → add rebase/stash/worktrees. [DOC] document the worktree limitation, the
  `@deprecated` GitDriver v2 plan, and HistoryEntry/HistoryManager.

### sugar-stickers
- [BUG] `Table/Table.php:82-104` — `sortBy()`/`sortByNext()` never call `Column::sorted()`, so the `▲`/`▼`
  arrow never renders → set the target column sorted + unsort others before `rebuildView()`. (Part C)
- [SEC] `Table.php:339-343`/`FlexBox.php:278-282` — `$cursorStyle`/`$headerStyle`/`$ansiStyle`/`FlexItem::$style`
  interpolated raw into SGR → validate `^[0-9;]*$` at the setters. (Part B)
- [DEADCODE] `Column.php` — `sortPriority()` stored but no code consumes it → implement multi-column sort or
  remove.
- [PERF] `TableRenderer.php:204-234` — `sgrToStyle()` maps only attribute codes, not colors (color-only frame
  changes dropped from the diff) → parse basic/256/RGB; `:101-192` `bufferFromOutput()` rebuilds the
  grapheme→style map every frame → cache by string hash; `FlexBox.php:114-201` memoize measured widths.
- [FEAT] `FlexBox` add `items()`/`removeItem()`/`withItems()`.
- [TEST] sort tests assert order only (why the arrow bug shipped) → add `testSortByRendersArrowGlyph`;
  `Column::sanitize()`/`FlexBox::sanitize()` zero tests → feed ESC/OSC/DCS; add align/wide-char/reset/
  formatter/`computeTotalWidth` tests.
- [DOC] the only Table port not cross-referencing the other three → add disambiguation; README oversells
  click-to-sort until the arrow fix → align + document the Column sort API.

### sugar-table
- [BUG] README:6 — Packagist badge targets `sugarcore/sugar-table` vs `sugarcraft/sugar-table` → fix image
  + link.
- [BUG] `Table.php` `computeTotalWidth` — documented not to converge on mixed Percent+Dynamic/Content →
  iterate to a bounded fixed point (or clamp+document) after pinning a golden.
- [DEDUP] `Sanitize.php:15-60` — near-identical to `candy-query/CellValue.php:50-67` → delegate to candy-core
  `Sanitize::cellValue(preserveNewlines)`. (W2)
- [PERF] `Table.php:970-1042` — `buildFilteredSortedRows()` runs `array_filter`×2 + `usort` over all rows per
  invalidation, no data virtualization (rendering is sliced) → optional lazy `RowSource`/chunked loading.
- [FEAT] no column-resize API, no mouse (click-to-sort/select) → add `withColumnWidthOverride()` + optional
  candy-mouse header hit-test, or document keyboard-first scope.
- [SEC] no export path exists and `Sanitize::value()` ignores `=`/`+`/`-`/`@` prefixes → README/CALIBER note
  directing future exports through `candy-query CsvExporter::guardFormula()`.
- [TEST] mixed Percent+Dynamic width golden; cache hit/miss counting across `View()` calls; filtered/sorted/
  frozen-col goldens → add.
- [DOC] add a "Known limitations" section (resize, mouse, computeTotalWidth caveat).

### sugar-tick
- [BUG] `bin/sugar-tick:64,86` — `export`/`gaps` accept unbounded `$days` (100-year walk) → clamp to a
  ceiling like `push`. (Part C)
- [FEAT] `push` never consults `Ignore/SugarTrackIgnore` (orphan) → load `.sugartrackignore` + skip ignored
  files; `Storage/SqliteBackend` unselectable + missing `ext-sqlite3` in composer → wire `--backend=sqlite`
  + declare the ext; `Milestone` never constructed + `milestones()` returns raw arrays → return `Milestone`
  instances + a `milestone add|list` subcommand (or delete both). (W15)
- [PERF] `Store.php:96-114` `append()` no `LOCK_EX` (interleave risk) → add it or enforce a line-length cap;
  `:39-60` `loadDay()` slurps whole files → stream with `fgets()`.
- [SEC] `Backup/AutoBackup.php:25-57` — `@`-suppressed mkdir/copy failures (silent data loss) → drop `@`,
  surface failures.
- [TEST] CLI dispatch entirely untested; ignore-file, backend-switch, Milestone composition, Renderer
  width<56 branch untested → add.
- [VHS] README embeds `push.gif` but only `dashboard.tape` exists → record `push.tape` or remove the ref.
- [DOC] README omits `export`/`gaps`/`backup` + `Milestone`/`AutoBackup`/`SqliteBackend`/`SugarTrackIgnore`/
  `GapsReport`/`Export\*` → document (mark unwired as roadmap); dedupe the `"sugarcraft"` keyword.

### sugar-toast
- [BUG] `Toast.php:602-609` — unterminated trailing `"\x1b["` ⇒ negative-length `substr` under strict_types →
  clamp length `>=0` + malformed-ANSI test. (Part C)
- [SEC] `Toast.php:601-614` — `placeAnsiStringAt()` only *accidentally* neutralizes non-`m` CSI (a future
  `m`-only fix reopens injection) → make the discard explicit (doc + deliberate drop) + a `\x1b[2J`/cursor-move
  regression test.
- [DEADCODE] `Toast.php:57,153-156` — `withAnimationDuration()` stores a value nothing reads → wire a
  time-based reveal into `renderAlertToBuffer()` or remove the setter (update README either way).
- [PERF] `Toast.php:460-575` — `View()` renders each alert twice (sizing + buffer) → build the Buffer once,
  read height via `Buffer::height()`; `:592-637` re-parses identical strings per frame → memoize by (content
  hash, width).
- [FEAT] no click-to-action hit-test though button layout is computed → `Toast::actionAt(x,y): ?Action`; no
  message length cap → `withMaxMessageLength()`.
- [DEDUP] queueing/severity/position reimplemented in `sugar-dash/Components/Toast/*` → sugar-toast becomes
  the engine, sugar-dash adapts. (W18)
- [TEST] once animation is wired, assert output differs across progress; add `ToastType::icon(SymbolSet)`
  matrix, `Position` offsets, `cancelAlert()`/`extendAlert()` out-of-bounds tests.
- [VHS] only basic/types → add `stacking-overflow`/`actions`. [DOC] state that `Action::callback()` doesn't
  auto-dismiss + animation is a no-op today.

### sugar-veil
- [SEC] `Veil.php:373-376` (root cause `Mouse\Mark::wrap()`) — `mark($id,...)` passes `$id` verbatim;
  U+E000/E001 in ids desync `Scanner` → reject sentinels in `Mark::wrap()` + document. (W3)
- [DEADCODE] `Veil.php:20,62-63,284-298` + `composer.json:33,122-128` — candy-zone `Manager` stored, never
  read → remove `withManager()`/`manager()`/import + the candy-zone require/path-repo (verified no external
  consumers); fix stale `CALIBER_LEARNINGS.md:59`. (W3.1)
- [PERF] `Veil.php:700` — per-position `mb_substr` ⇒ O(n²)/frame → `mb_str_split` once; `:454-539`
  `composite()` rebuilds every background row each frame → recompute only `[y, y+fgHeight)` + dim-changed
  rows; `VeilStack.php:77-122` no per-veil bbox clipping → clip.
- [FEAT] `VeilStack` no z-order `hit()`/topmost accessor → `topmost()`/`hitTopmost()`; no focus-trap/key-routing
  → document + optional `isKeyForVeil()`.
- [TEST] delete the byte-identical mislabeled duplicate `testIsClickOutsideReturnsFalseWhenManagerNotSet`
  (`VeilTest.php:335-342`); add VeilStack hit-testing, animated+backdrop+clickOutside, `withoutSession()`
  corruption, and a `bufferFromOutput()` wide-multibyte perf-bound test.
- [VHS] tapes never demo `withBackdrop`/`withAnimation`/`withClickOutsideDismiss` → add `animated-modal.tape`.
- [DOC] fix `CALIBER_LEARNINGS.md:59` (stale `anyInBounds`) + README byte-savings claim scope.

### sugar-wishlist
- [SEC] `Config.php:218-226` (+casts `:204,210,213,215`) — non-scalar `name`/`host`/`port`/`user`/`options[]`/
  `identityFiles[]` silently `(string)`/`(int)`-cast (`ssh user@Array`) → `is_scalar()` guards throwing
  `InvalidArgumentException` (mirror candy-mines Board.php:260). (Part B/W8)
- [BUG] `Endpoint.php:49-52` — `toSshArgv()` emits only `identityFiles[0]`, dropping the rest → one `-i <file>`
  per identity file.
- [FEAT] `bin/wishlist:44-72` — `importFromSshConfig()` unreachable (no flag) → add `--ssh-config[=path]`
  merging imported hosts.
- [PERF] `Picker.php:117-165` — `filterMatches()` reruns full fuzzy per keystroke → rescore only the prior
  match set when the pattern extends; `:170-189` per-line `fwrite()` → buffer + one write.
- [FEAT] no MRU/frecency ordering → optional last-connected persistence + recency tiebreak.
- [TEST] no non-scalar field-shape tests, no multi-`identityFiles` `toSshArgv()` test → add.
- [DOC] README:96-148 duplicates "Programmatic Use" verbatim → merge; document `--ssh <path>` + the
  first-identity-file-only limitation.

### docs-website
- [BUG] `docs/lib/*.html` (60 occurrences) — "Full README" → `blob/main/` while siblings use `blob/master/`
  (404s) → single sweep `main`→`master` + a CI grep guard. (W14.1)
- [BUG] `docs/index.html:7,9,24,69,181,635` — "33 libraries and 23 apps (56 packages)" vs 58 → recount from
  MATCHUPS.md, update all six (computed once generated). (W14.2)
- [BUG] `docs/img/icons/` stale unsynced copy — missing `candy-mosaic.png` (referenced at `:130,360`), orphan
  `sugar-dash.png` (exists nowhere else) → copy sugar-dash.png into `media/icons/`, restore candy-mosaic.png,
  add a media→docs sync + basename-diff CI check. (W14.4)
- [FEAT] 5 libs iconless + 8 1×1 stubs (13/58) → real artwork for all 13, remove the `onerror` crutches. (W14.5)
- [FEAT] the whole tree is hand-maintained (root cause of the 60× bug) → `tools/gen-docs.php` from a shared
  template + per-lib metadata; replace the hand-edit checklist steps. (W14.6)
- [FEAT] Codecov absent from all 58 pages though every README has a badge → add per-lib (reuse README URL);
  MATCHUPS status badges never surface on the site → render per-lib; no breadcrumb back to `#libraries` → add.
- [DOC] stray `docs/lib/honey-bounce.md` → delete; stale `index.html:367` candy-mouse TODO → reword; inline
  section `style="…"` duplicated `:179,876,1136` → hoist to a CSS class (folds into the generator).

---

## Part E — Sequencing & PR bundling

Ship-as-you-go: branch `ai/<slug>-<short>`, 2–4 related items/PR, every touched lib green
(`composer update` then `vendor/bin/phpunit`) before merge, dependency order, author
`Joe Huss <detain@interserver.net>`.

**Phase 0 — Stop-the-bleeding (P0, each its own tiny PR):**
1. candy-vt regressed parser bug (W1.1) — first.
2. candy-files trash-dir data loss (Part C); sugar-skate broken CLI; candy-wish broken example.
3. Contained-blast-radius security: candy-serve header-auth + packfile-unpack, candy-metrics eviction,
   candy-pty FD/TOCTOU/libc, candy-shell env-leak + timeout, candy-forms field sanitize, candy-input UTF-8 +
   DoS caps, sugar-crush hook safe-default, sugar-post 465 TLS, sugar-readline history perms.
4. docs `blob/main→master` sweep + package count + CI guard (W14.1–2).
5. One-file correctness fixes: sugar-spark `finish()` pair, sugar-stickers sort-arrow, sugar-stash rename +
   worktree launch, candy-kit `microtime`, candy-vcr `Output`.

**Phase 1 — Foundation correctness & de-dup (P1, dependency-ordered):**
6. W2 sanitizer (candy-core) → unblocks candy-forms/log/table/dash/query sanitize.
7. W8 candy-core `AtomicJsonFile`/`Json::decodeArray` → unblocks the games/persistence + is_array fixes.
8. W12 candy-layout solver → unblocks sugar-boxer.
9. W1 remainder (step-12 migration) after the hotfix + candy-ansi seams.
10. W4 fuzzy SSOT + candy-async `OperationCancelledException`.
11. W3 bubblezone merge (start with the two inert-dependency drops).
12. W5 theme SSOT; W6 harness (`TemporaryDirectoryTrait`/`for(Model)`); W16 status-bar; W17 façade-test delete;
    W18 toast engine.
13. W9 shine/freeze highlight; W10 serve/wish; W11 glow→shine; W7 path-repo prune (after W1/W12 settle).

**Phase 2 — Per-lib functionality + performance (P2):** work Part D lib-by-lib, foundation `candy-*` before
`sugar-*`, 2–4 items/PR. Adopt W6/W8 opportunistically as each lib is opened.

**Phase 3 — Coverage, VHS, docs, dead-code (P3):** batch per lib; add missing `.tape` demos (candy-vcr
renders ~6 min/tape, commit GIFs, register non-visual exemptions + new libs in `vhs.yml`), fill
README/CALIBER gaps, delete dead code. W14 icon backfill + the optional docs generator land here.

---

## Part F — Verification

- **Per lib touched:** `cd <lib> && composer update --quiet && vendor/bin/phpunit` green; `composer validate`
  (no `--strict`). Every fix adds/updates a test that fails before and passes after.
- **Security fixes:** each has a regression test encoding the exploit (impersonation header rejected,
  cardinality memory drops, packfile validated, env-var not expanded, ESC stripped per field, path-traversal
  blocked, TLS wraps at connect, history file 0600 from creation, non-scalar config throws).
- **Correctness fixes:** drive the real entrypoint — smoke-test `bin/skate`, run the candy-wish example, feed
  the DCS/APC sequence through candy-vt, exercise `StreamingInspector::finish()` at end-of-stream, render a
  sorted sugar-stickers table and assert the arrow, delete-then-undo in candy-files.
- **Cross-cutting:** after W7, `php scripts/check-path-repos.php` clean; after W2/W4/W5, one canonical impl
  with the others delegating (alias/closure test); after W1, candy-vt imports `SugarCraft\Ansi\Parser` (grep
  shows it, the fork is gone); after W17, no duplicated façade test dirs remain.
- **Foundation-first ripple:** after changing candy-core/ansi/sprinkles/layout/testing, run the consumer
  smoke set: `for d in candy-core candy-sprinkles candy-forms honey-bounce candy-zone sugar-bits sugar-charts candy-shell candy-shine; do (cd "$d" && composer update --quiet && vendor/bin/phpunit) || break; done`
- **Docs:** after W14, no `blob/main/` under `docs/lib/`; package count reads 58; Codecov links resolve;
  `docs/img/icons/` matches `media/icons/`.
- **VHS:** re-render touched tapes via candy-vcr; confirm `vhs.yml`'s hand-maintained `all=(...)` includes any
  newly-demoed lib.

---

## Appendix — Libraries whose real scope differs from a first guess

Act only on real findings; never change a lib's purpose. sugar-crush = AI coding-agent chat shell
(charmbracelet/crush); sugar-skate = SQLite KV store (charmbracelet/skate); sugar-spark = ANSI-sequence
inspector (charmbracelet/sequin); sugar-post = email sender (charmbracelet/pop); sugar-wishlist = SSH endpoint
launcher (charmbracelet/wishlist, unrelated to candy-wish); sugar-tick = WakaTime-style time tracker;
sugar-reel = video/media player; sugar-stash = lazygit-style git TUI; honey-flap = Flappy-Bird game;
candy-kit = charmbracelet/fang (not bubbles); candy-layout = ratatui-style constraint solver (not lipgloss
Join/Place); candy-mouse = bubblezone hit-tester; candy-palette = colorprofile detection; candy-mines/
candy-tetris = games; candy-mold = `create-project` skeleton. sugar-bits = bubbles façade with `class_alias`
re-exports to candy-forms.
