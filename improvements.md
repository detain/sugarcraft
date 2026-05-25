# SugarCraft monorepo improvements

**Date:** 2026-05-21
**Scope:** Full monorepo (~45 libs under `candy-*`, `sugar-*`, `honey-*`, `super-candy`)
**Methodology:** Three-axis audit (cross-lib boundaries, repeated patterns, ergonomics) plus direct file verification on the four highest-impact claims.

**Severity tiers:** CRITICAL · HIGH · MEDIUM · LOW · ACHIEVED (already good — listed for confidence)

---

## §1. Executive summary

1. One **architectural** problem: `candy-shell` (a foundation lib) `require`s `sugar-bits` + `sugar-prompt` (leaf libs). The dep graph points the wrong way.
2. ~700 lines of removable boilerplate from cloned `Lang.php` files (33 libs, ~31 lines each, all the same shape).
3. `sugar-glow/src/WidthHelper.php` (107 lines) reimplements `candy-core/src/Util/Width.php`; delete it.
4. ANSI-strip regex and hand-built SGR escape sequences are scattered across 6+ leaf libs that should be calling `candy-core/Util/Ansi` and `candy-palette/Color`.
5. Two incompatible Boxer layout engines (`sugar-boxer` vs `sugar-dash/src/Layout/Boxer`).
6. Almost everything else — README quickstarts, examples, type signatures, defaults, error messages, `final` policy — is already in great shape. See §6.

---

## Execution protocol — applies to every PR in this document

Every numbered item below (§2.1, §3.1, §3.2, …) is shipped through the same lifecycle. Read this once; it isn't repeated under each item.

### 1. One subagent per phase, sequentially — never concurrently

Spawn **one** subagent (general-purpose or `oac:coder-agent`) per PR. Wait for it to finish before spawning the next. Concurrent agents in this repo collide on shared root files (`MATCHUPS.md`, root `README.md`, root `composer.json`, `.codenomad/worktreeMap.json`) — the project's `AGENTS.md` gotchas section calls this out explicitly. Background mode is fine, but only one in flight at a time.

This rule also prevents context bloat in the orchestrating session — each subagent reports a short summary back; the orchestrator never carries the full diff in its window.

### 2. Phase work

The subagent reads its scope from this document, makes the changes, and never expands scope without checking back. If a phase touches more files than the spec lists, the subagent stops and reports.

### 3. Local gate — all three must pass before commit

Per affected lib:

```sh
composer install --quiet
vendor/bin/phpunit                                            # tests
vendor/bin/phpstan analyze --level=max 2>/dev/null || true    # if configured
vendor/bin/php-cs-fixer fix --dry-run --diff 2>/dev/null || true  # if configured
```

Current state (verified 2026-05-21):
- **phpunit:** configured per lib (45 libs).
- **phpstan:** only `candy-core/phpstan.neon` exists today. Phases touching other libs **should set up phpstan as part of the same PR** — bring the lib up to the project's standard while we're in there.
- **php-cs-fixer:** not configured anywhere yet. Add a root `.php-cs-fixer.dist.php` as part of the first phase that ships, then per-lib gates can use it.

The PHPUnit hang gotcha (PTY/FFI tests) still applies — see the `feedback_phpunit_kill_pattern` project memory: backgrounded `pkill -f phpunit` watchdog at 120s if needed.

### 4. Review cycle — review, fix, repeat until clean

After the subagent's local gate passes, spawn a **separate** review subagent (use the `oac:code-review` skill, or general-purpose with the diff as input). The reviewer:
- Cross-checks against the phase's spec in this document.
- Validates against the touched lib's `CALIBER_LEARNINGS.md`.
- Flags any deviation from project conventions (PSR-12, immutable + fluent, `Lang::t()` for exceptions, etc.).

If the reviewer finds issues, spawn **one more** subagent to apply fixes. Re-run the local gate. Re-review. Cycle until the reviewer signs off. Two cycles is normal; three suggests the spec was unclear and should be tightened in this document before continuing.

### 5. Documentation — required, not optional

Every PR updates documentation that's affected by the change. For each touched lib:
- `<slug>/README.md` — Quickstart still runs; new public APIs reflected.
- `<slug>/CALIBER_LEARNINGS.md` — append any new pattern or anti-pattern learned.
- `docs/lib/<slug>.html` — public webpage tile if user-facing surface changed.

For cross-cut changes (e.g., `LangFactory` extraction, new `candy-forms` lib):
- Root `README.md` — library table row(s).
- Root `MATCHUPS.md` — status icon updates.
- `docs/index.html` — homepage tile grid.
- Root `CALIBER_LEARNINGS.md` — cross-cut pattern.

### 5b. Caliber — skip entirely for these PRs

This repo's CLAUDE.md / AGENTS.md tell humans to run `caliber refresh` before commit. **For AI-driven PRs under this document, skip Caliber.** If Caliber's pre-commit hook fires and stages files on its own, unstage them and proceed:

```sh
git restore --staged \
  CLAUDE.md AGENTS.md .claude/ .cursor/ .cursorrules \
  .github/copilot-instructions.md .github/instructions/ \
  .agents/ .opencode/ 2>/dev/null
git commit ...   # continue as if Caliber never ran
```

Hand-written additions to a lib's `CALIBER_LEARNINGS.md` (per §5) are intentional content edits — keep those staged. Only unstage files Caliber regenerates on its own.

If a stop hook complains that Caliber isn't set up on the machine, ignore it.

### 6. Ship via the `ship-pr` skill

The project's `ship-pr` skill is invoked at the end and handles: stage → commit (author `Joe Huss <detain@interserver.net>`) → push → create PR → merge → checkout master → pull.

**Every `gh` invocation in this repo MUST be prefixed with `unset GITHUB_TOKEN &&`**. The repo's `GITHUB_TOKEN` env var is set for CI and conflicts with local `gh auth`. The `ship-pr` skill knows this; any direct `gh` calls outside the skill need the same prefix:

```sh
unset GITHUB_TOKEN && gh pr create ...
unset GITHUB_TOKEN && gh pr merge <n> --merge --delete-branch
```

### 7. Post-merge cleanup — return to master, prune the branch

After `gh pr merge <n> --merge --delete-branch` (the skill already passes `--delete-branch` so the remote is gone), the orchestrator subagent does the local cleanup before reporting done:

```sh
git checkout master
git pull --ff-only
git branch -d ai/<slug>-<short>    # delete the merged local branch
git fetch --prune                  # drop stale remote-tracking refs
git status                         # must show "working tree clean" on master
```

If `git branch -d` refuses (says the branch isn't merged), something went wrong — investigate before forcing. Don't use `-D` to bypass; the merge may have failed silently.

Before the **next** phase spawns, the orchestrator verifies:
- Current branch is `master`.
- `git status` is clean.
- `git log -1 --oneline` shows the merge commit just pulled.

### 8. Move to the next phase only after cleanup

Never queue two phases concurrently against the same lib, and never start a new phase from a stale branch. The cleanup in §7 is the gate.

---

## §2. CRITICAL — architectural

### 2.1 candy-shell depends on sugar-bits + sugar-prompt (inverted dependency)

✅ Resolved (PR #871): candy-shell now requires candy-forms; sugar-bits/sugar-prompt deps dropped. Spinner moved into candy-forms in PR #870.

**Evidence:** `candy-shell/composer.json` lists `sugarcraft/sugar-bits` and `sugarcraft/sugar-prompt` in `require`. Eight files under `candy-shell/src/Model/` (`ChooseModel.php`, `ConfirmModel.php`, `FileModel.php`, `FilterModel.php`, `InputModel.php`, `PagerModel.php`, `SpinModel.php`, `WriteModel.php`) `use` classes from `SugarCraft\Bits\*` / `SugarCraft\Prompt\*`. `candy-shell/src/Command/SpinCommand.php` does the same.

**Why this matters:** Per the project's prefix conventions (CLAUDE.md, AGENTS.md), `Candy-` is foundation; `Sugar-` is leaf. A foundation lib cannot depend on a leaf lib without inverting the dependency graph, which kills the ability to publish `candy-shell` standalone post-1.0 and creates implicit cycles once sugar-bits or sugar-prompt grows a back-reference.

**Options:**

- **Option A — preferred. Extract `candy-forms` foundation lib.** Move the reusable form/input primitives (`TextInput`, `TextArea`, `ItemList`, `Viewport`, `FilePicker` from sugar-bits; `Confirm` from sugar-prompt) into a new foundation lib `candy-forms`. Then candy-shell, sugar-bits, and sugar-prompt all depend on candy-forms. sugar-bits and sugar-prompt remain leaf libs that re-export or compose candy-forms primitives. Effort: medium (multi-PR phased) — touches 3 libs + every consuming composer.json. No public-API break if sugar-bits and sugar-prompt keep the same class names via aliasing during transition.

- **Option B — minimal. Rename `candy-shell` → `sugar-shell`.** Reclassify as a leaf. Cheaper but loses the framing of "gum-port-as-foundation-CLI", and renames in this repo are non-trivial (root composer.json, MATCHUPS.md, PROJECT_NAMES.md, docs/, workflows, etc. — there's a CLAUDE.md note that renames go through a separate flow).

Recommend Option A. Tracked separately because of size — gate the work on explicit user go-ahead.

---

## §3. HIGH — duplication to pay down

### 3.1 Delete sugar-glow/src/WidthHelper.php

`candy-core/src/Util/Width.php` already provides `Width::string()`, `Width::padRight()`, `Width::truncate()`. `sugar-glow/src/WidthHelper.php` reimplements the same operations via `mb_strwidth` + ANSI-strip regex in 107 lines. Delete the file; ensure `sugar-glow/composer.json` `require`s `sugarcraft/candy-core` (it already transitively will via sugar-bits); rewrite call-sites.

### 3.2 ANSI-strip regex duplicated across leaf libs

Same `preg_replace('/\x1b\[[0-9;]*m/', '', $str)` pattern (plus `mb_strwidth` or `mb_strlen`) appears in:
- `sugar-dash/src/Components/Feedback/Alert.php`
- `sugar-dash/src/Components/Nav/Scrollbar.php`
- `sugar-veil/src/Animation/Slide.php`
- `sugar-dash/src/Plot/Chart/OHLC.php`

Replace each with `\SugarCraft\Core\Util\Ansi::strip()` followed by `\SugarCraft\Core\Util\Width::string()`. This is the lowest-risk batch in the entire audit.

### 3.3 Hand-built SGR escape sequences

Inline `"\x1b[31m"` / `"\x1b[1m"` / `"\x1b[0m"` strings appear in:
- `sugar-charts/src/Legend/Legend.php`
- `sugar-dash/src/Foundation/Style.php`
- `sugar-dash/src/Components/System/Terminal.php`
- `sugar-glow/src/Highlighter/ChromaJsonHighlighter.php`
- `sugar-dash/src/Foundation/Buffer.php`
- `sugar-dash/src/Plot/Braille/BrailleCanvas.php`

Replace with `\SugarCraft\Core\Util\Ansi::sgr(Ansi::BOLD)` / `Ansi::reset()` / `\SugarCraft\Palette\Color::toFg(ColorProfile::...)`. The candy-core `Ansi` class is the canonical home for SGR constants and builders.

✅ Residuals cleaned (this PR): bare \x1b[0m/\x1b[2m/\x1b[7m and candy-tetris 256-color builders routed through Ansi helpers across sugar-bits, sugar-crush, sugar-dash, sugar-veil, candy-hermit, candy-shell, candy-tetris (including candy-tetris/VsRenderer.php bg256/ghost builders and sugar-crush Renderer.php composite opening codes \x1b[1;36m/\x1b[1;35m/\x1b[2m → Ansi::sgr(...)). candy-mosaic/Detect.php left as-is (terminal queries, not SGR).

### 3.4 Two Boxer implementations — ⏭️ NOT a duplication; keep both (evaluated, won't consolidate)

**Original claim (incorrect):** that `sugar-boxer/src/SugarBoxer.php` and `sugar-dash/src/Layout/Boxer/` are two ports of the same "box layout engine" and the latter should be dropped in favor of the former.

**Finding after reading both implementations:** they are legitimately different things that happen to share the word "Boxer", and consolidating them would be net-harmful. Evidence:

- **Different upstreams.** `sugar-boxer` doc-comments cite `treilik/bubbleboxer`; `sugar-dash/src/Layout/Boxer` cites `charmbracelet/boxer`. Two distinct upstream projects, not a duplicate port.
- **Opposite rendering models, zero shared algorithm.** `SugarBoxer` paints onto a mutable 2D cell grid — it draws box-drawing borders around leaf text it word-wraps itself, distributing space by minWidth/minHeight weights. `sugar-dash`'s `Node::renderTree` is a line-list compositor — it calls each leaf model's own `render()`, validates the returned lines against the allocated size (returns `SizeError` on overflow), and `implode()`s panels with single-char separators. One paints a grid; the other concatenates pre-rendered strings.
- **sugar-dash's Boxer is deeply wired into sugar-dash's own systems** that `sugar-boxer` has no concept of: `implements Item, Sizer`, dotted-address navigation, an address→`Item` model map (renders live component output, not static strings), interactive `editLeaf`, `persistState`/`restoreState` via `Persistence`, and `withTheme` fan-out. `sugar-boxer` takes one static string per leaf.
- A "thin adapter" would not be thin — it would amount to reimplementing `sugar-dash`'s ~825-line Boxer/Node (36-test suite in `sugar-dash/tests/Layout/BoxerTest.php`) on top of an API that lacks model maps, addresses, Sizer propagation, persistence, and themes.

**Resolution:** keep both. `sugar-boxer` = static bordered-box painter (treilik/bubbleboxer). `sugar-dash`'s `Layout\Boxer` = stateful address-tree panel compositor (charmbracelet/boxer). The namespaces (`SugarCraft\Boxer` vs `SugarCraft\Dash\Layout\Boxer`) already disambiguate them. A future rename of the sugar-dash class (e.g. `PanelTree`) to reduce the name clash is optional and would go through the repo's separate rename flow — not pursued here.

### 3.5 33 cloned Lang.php files

Each follows the same ~31-line shape (verified — `ls */src/Lang.php | wc -l` = 33). Only `NAMESPACE` and `DIR` constants differ.

Fix: introduce `SugarCraft\Core\I18n\LangFactory::for(string $namespace, string $dir): callable` and update the `scaffold-library` skill to emit a one-line delegating Lang.php. Migrate all 33 in one mechanical PR. Net delete: ~700 lines. No public-API change — `Lang::t('foo')` continues to work.

Alternative shape (if static methods are a hard requirement): a `LangTrait` that classes use; constants `NAMESPACE` and `DIR` are the only per-lib variation; trait body provides `static function t()`.

### 3.6 with*() / mutate() shape divergence

The project standard is "every `with*()` returns a new instance via private `mutate()` helper" (AGENTS.md, CALIBER_LEARNINGS.md). The shape of that helper has drifted:

- Named-param: `mutate(cursor: $cursor)` — used in `sugar-bits/src/TextArea/TextArea.php`
- Positional: `mutate(0, 0.0, null)` — used in `candy-zone/src/ClickCounter.php`
- Named + sentinel bools for nullable fields: `mutate(boundString: $content, boundStringSet: true, propsAdded: ['boundString'])` — used in `candy-sprinkles/src/Style.php`

Pre-1.0 is the time to standardize. Recommend the named-param `mutate()` via `new static(...array_merge(get_object_vars($this), $changes))` for libs whose properties are all nullable-or-required-but-set; for libs with sentinel-bool needs (Style), document the exception. Optionally formalize via a `SugarCraft\Core\Concerns\Mutable` trait, but this is judgment-call territory because typed-property constructors can disagree with trait spreads.

---

## §4. MEDIUM — soft duplication and shoulds

### 4.1 Terminal raw-mode lives in the wrong places

✅ Resolved (PR #873): sugar-wishlist raw-mode now routed through SugarCraft\Core\Util\RawMode; shell_exec('stty') removed from the leaf lib. (The stream_isatty cases in sugar-prompt/Spinner and sugar-glow/RenderCommand were already routed through TtyDetect in PR #866.)

- `sugar-wishlist/src/Picker.php` calls `shell_exec('stty -icanon -echo min 1 time 0')` and `shell_exec('stty sane')`.
- `sugar-prompt/src/Spinner.php` calls `stream_isatty(STDERR)` directly.
- `sugar-glow/src/RenderCommand.php` calls `stream_isatty(STDIN)` directly.

`candy-core/src/Util/TtyDetect.php` already exists. Inject it. For raw-mode toggling, candy-pty is the natural home, or introduce a new `candy-term` foundation lib that owns terminal-control concerns. Either way, `shell_exec('stty …')` belongs out of leaf libs.

### 4.2 phpunit.xml duplication

45 libs, identical configs except for `<testsuite name="…">`. Optional refactor: root `phpunit.xml.dist` with common settings + per-lib `phpunit.xml` extending it. Saves ~800 lines but the maintenance burden today is near zero — flag as nice-to-have rather than a priority.

### 4.3 Stream-write test pattern repeated in ~15 test files

`ftell` → operation → `fseek` → `stream_get_contents` is documented in `candy-core/tests/RendererTest.php` and repeated across many test files. Extract `SugarCraft\Core\Tests\StreamHelper` trait + a base `SugarCraft\Core\Tests\TestCase` that the libs' test suites can inherit. Low-risk consolidation.

### 4.4 sugar-prompt validator chaining is verbose

`Input::new('email')->withValidator(new Required())->withValidator(new Email())` could be `Input::new('email')->required()->email()`. Other field methods already follow the short-form pattern (`->placeholder('…')`, `->charLimit(100)`). Validators are the inconsistency.

---

## §5. LOW — polish

### 5.1 Add catalog() discovery methods

✅ Resolved (PR #875): added `candy-sprinkles\Border::catalog()`, `candy-sprinkles\Theme::catalog()`, and `candy-palette\Color::namedColors()` (delegates to `StandardColors::catalog()`). `Forms\Theme::catalog()`, `candy-palette StandardColors::catalog()`, and sugar-bits Spinner `Style::catalog()` already existed.

Themes, spinners, borders, named colors are discoverable today only via README / IDE autocomplete / `grep src/`. Add:
- `Theme::catalog(): list<string>`
- `Spinner::catalog(): list<string>`
- `Border::catalog(): list<string>`
- `Color::namedColors(): list<string>`

Enables programmatic enumeration (e.g., for a `gum style --list-themes` style command).

### 5.2 Composability cookbook

✅ Resolved (PR #876): `docs/cookbook/` with 3 runnable cross-lib examples — `spinner-in-table.php` (candy-forms Spinner embedded in a sugar-table StyledCell), `sparkline-in-table.php` (sugar-charts Sparkline + sugar-table + candy-forms Spinner panel), `program-within-program.php` (candy-core Model nesting a candy-forms Spinner sub-program). Each builds the model(s), feeds scripted messages, renders one frame, and exits 0 (verified via `php docs/cookbook/<name>.php`). `docs/cookbook/composer.json` path-requires the libs; `docs/cookbook/README.md` indexes them; `docs/index.html` links the cookbook. The Toast+form candidate was dropped: sugar-toast's `View()` overlay truncates its own box borders (reproduces in `sugar-toast/examples/basic.php` too), so it would have shipped visibly broken (fixed in this PR).

`docs/cookbook/` with 3–5 worked examples:
- Embedding a `sugar-prompt` Form inside a `sugar-table` cell
- Spinner overlay on top of a `sugar-charts` plot
- Toast + form interleaving
- Sub-program-in-a-sub-program (program-within-program)

Each example is a small runnable PHP script. Composability is asserted in the project but rarely demonstrated end-to-end.

### 5.3 Formalize factory-naming convention in CLAUDE.md

✅ Resolved (PR #875): added an explicit "Factory naming" rule to both `AGENTS.md` and `CLAUDE.md` Code-conventions sections — `::new()` is the zero-arg root, bare-named factories for variants, no `::create()`/`::make()`/`::default()`.

Current pattern (consistent enough already): `::new()` for the zero-arg/default root instance, bare-named factories for variants (`Theme::ansi()`, `Spinner::line()`). Document the rule explicitly so future libs don't drift to `::create()` / `::make()` / `::default()`.

---

## §6. ACHIEVED — already excellent (no action required)

Recorded here so future audits don't re-litigate:

- **Constructor cliffs:** none. Large constructors are private; public `::new()` factory provides sensible defaults.
- **README quickstarts:** 43/43 sampled libs have working `composer require` + 5-line snippets.
- **examples/ directory coverage:** 42/43 libs. `super-candy` is an intentional exception (it's a binary, not a lib).
- **Error messages:** every `throw new \InvalidArgumentException(...)` and `\RuntimeException(...)` uses `Lang::t($key, $params)`. No bare English strings found in exception paths.
- **Type signatures:** full parameter and return-type coverage. `mixed` is used only where genuinely heterogeneous (e.g., `Field::value(): mixed`).
- **Composer keywords:** 5–8 per lib, including upstream Go name + `sugarcraft`.
- **`final` policy:** ~99.5% adherence. The three intentional non-finals (`Exception`, `MouseMsg`, `KeyMsg`) are extension points by design.
- **ReactPHP integration:** `ProgramOptions` accepts `LoopInterface`; `Subscriptions` documented with worked examples.
- **Sensible defaults:** components like `TextInput`, `Table` need 2 method calls for a working baseline.

---

## §7. Suggested execution order

Each step is a self-contained PR ending with the local gate (phpunit + phpstan + cs-fixer) per affected lib, then the review cycle, docs updates, and ship-pr per the Execution protocol above.

1. **Quick wins (1 PR, ~½ day):** Delete `sugar-glow/src/WidthHelper.php`. Replace ANSI-strip regex in `sugar-dash` (Alert, Scrollbar, OHLC) and `sugar-veil` (Slide) with `Ansi::strip()` + `Width::string()`.
2. **SGR cleanup (2 PRs):** sugar-dash batch (Foundation/Style, Foundation/Buffer, Components/System/Terminal, Plot/Braille/BrailleCanvas), then sugar-charts + sugar-glow batch.
3. **Lang.php consolidation (1 PR):** Add `SugarCraft\Core\I18n\LangFactory` + update `scaffold-library` skill + mechanically migrate 33 libs.
4. **Boxer consolidation (1 PR):** sugar-dash drops `Layout/Boxer/` and adopts `sugar-boxer/SugarBoxer`.
5. **`candy-forms` extraction (multi-PR phased, gated):** Extract `candy-forms`; flip `candy-shell` requires; aliasing in sugar-bits/sugar-prompt during transition. Largest item — needs explicit go-ahead.
6. **Polish round (1 PR):** `catalog()` methods, sugar-prompt validator shortcuts, `TestCase` base, `StreamHelper` trait.
7. **Optional / opportunistic:** shared `phpunit.xml.dist`; `candy-term` foundation lib for raw-mode.

---

## §8. Out of scope for this document

- Per-lib functional bugs (not surfaced by an audit at this altitude).
- CI / workflow changes beyond what consolidations require.
- Library renames (handled by a separate flow per CLAUDE.md).
- `CHANGELOG.md` / `UPGRADE_GUIDE` — pre-1.0, deferred by project policy.
- candy-vcr / candy-vt work — covered separately in `vcr_use.md`.
