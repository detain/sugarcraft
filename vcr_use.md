# Making candy-vcr a charmbracelet/vhs replacement

**Date:** 2026-05-21
**Question:** Can candy-vcr take over `.tape` → `.gif` rendering from the upstream charmbracelet/vhs binary the CI workflow currently uses?
**Verdict:** Yes — with a focused **6–10 week** build. The biggest hidden cost is that **candy-vt** (the terminal-grid emulator candy-vcr would render through) is essentially unbuilt today. candy-vcr's existing Cassette + Player layer is solid and reusable; the tape compiler, renderer, rasterizer, and GIF encoder are net-new.

---

## §1. TL;DR

candy-vcr today is a **Msg-flow recorder/replayer** (Program → JSONL cassette → replay with assertions). charmbracelet/vhs is a **declarative tape DSL → terminal rendering → GIF**. They solve different problems. To make candy-vcr cover what vhs does, we build a new pipeline on top of the existing Cassette + Player layer:

```
.tape  →  Lexer/Parser/Compiler  →  Cassette  →  Player  →  Terminal (candy-vt)
                                                              ↓
                                                          Snapshot stream
                                                              ↓
                                                          Rasterizer (gd/imagick)
                                                              ↓
                                                          PNG frames
                                                              ↓
                                                          GifEncoder (ffmpeg)
                                                              ↓
                                                              .gif
```

ffmpeg is already in the CI runner image. `gd`, `imagick`, and `ffi` PHP extensions all load in local PHP. The PHP-native path is realistic; we don't need Chromium or ttyd. Strategy: ship as additive (new CLI command, new container image variant) and A/B alongside upstream vhs so rollback is one workflow-file change.

---

## Execution protocol — applies to every phase in this document

Every phase below (Phase 0 through Phase 7) ships through the same lifecycle. Read this once; it isn't repeated under each phase.

### 1. One subagent per phase, sequentially — never concurrently

Spawn **one** subagent (general-purpose or `oac:coder-agent`) per phase. Wait for completion before spawning the next. Concurrent subagents collide on shared root files (`MATCHUPS.md`, root `README.md`, root `composer.json`, `.codenomad/worktreeMap.json`); see the AGENTS.md gotchas. Background mode is fine, but only one in flight at a time.

This also prevents context bloat in the orchestrating session — each subagent reports a short summary; the orchestrator never carries the full diff in its window.

### 2. Phase work

The subagent reads its scope from this document, makes the changes, never expands scope without checking back. Phase 1 (candy-vt) is large enough that it should be sub-divided across multiple PR subagents (one for value objects, one for the CSI parser, one for the theme catalog, etc.) — still one at a time.

### 3. Local gate — all three must pass before commit

Per affected lib:

```sh
composer install --quiet
vendor/bin/phpunit                                            # tests
vendor/bin/phpstan analyze --level=max 2>/dev/null || true    # if configured
vendor/bin/php-cs-fixer fix --dry-run --diff 2>/dev/null || true  # if configured
```

Current state (verified 2026-05-21):
- **phpunit:** configured per lib.
- **phpstan:** only `candy-core/phpstan.neon` exists today. **Phase 0 should add `phpstan.neon` to `candy-vcr` and `candy-vt`**; subsequent phases inherit. Aim for `level: max` from day one — easier than back-fitting later.
- **php-cs-fixer:** not configured. Phase 0 should add a root `.php-cs-fixer.dist.php` so every subsequent phase has a lint gate.

PHPUnit hang gotcha (PTY/FFI tests in candy-pty, candy-vcr's PTY-recording paths) applies — see the `feedback_phpunit_kill_pattern` project memory: backgrounded `pkill -f phpunit` watchdog at 120s if needed.

### 4. Review cycle — review, fix, repeat until clean

After the local gate passes, spawn a **separate** review subagent (use `oac:code-review` skill, or general-purpose with the diff as input). The reviewer:
- Cross-checks against the phase spec in this document.
- Validates against `candy-vt/CALIBER_LEARNINGS.md` and `candy-vcr/CALIBER_LEARNINGS.md`.
- Checks project conventions: PSR-12, `declare(strict_types=1)`, `final` classes, immutable + fluent + `mutate()` pattern, `Lang::t()` for user-facing exceptions.

If the reviewer finds issues, spawn **one more** subagent to apply fixes. Re-run the local gate. Re-review. Cycle until clean. Two cycles is normal; three suggests this document's phase spec is unclear and should be tightened first.

### 5. Documentation — required, not optional

Every phase updates docs that the change affects:

Per touched lib:
- `<slug>/README.md` — quickstart still works; new public APIs documented with code snippets.
- `<slug>/CALIBER_LEARNINGS.md` — append patterns and anti-patterns from the phase.
- `<slug>/.vhs/*.tape` + `.gif` — if the lib gains demo-worthy behavior.
- `docs/lib/<slug>.html` — public webpage if user-facing surface changed.

For cross-cut additions (new candy-vt lib, new CLI subcommands):
- Root `README.md` — library count, table row, test-loop snippet.
- Root `MATCHUPS.md` — status icon updates.
- Root `PROJECT_NAMES.md` — naming entry for any new lib.
- `docs/index.html` — homepage tile.
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

The project's `ship-pr` skill handles: stage → commit (author `Joe Huss <detain@interserver.net>`) → push → create PR → merge → checkout master → pull.

**Every `gh` invocation in this repo MUST be prefixed with `unset GITHUB_TOKEN &&`**. The repo's `GITHUB_TOKEN` conflicts with local `gh auth`. The ship-pr skill knows this; any direct `gh` invocations outside the skill need the same prefix:

```sh
unset GITHUB_TOKEN && gh pr create ...
unset GITHUB_TOKEN && gh pr merge <n> --merge --delete-branch
```

### 7. Post-merge cleanup — return to master, prune the branch

After `gh pr merge <n> --merge --delete-branch` (the skill passes `--delete-branch`, so the remote is gone), the subagent does local cleanup before reporting done:

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

Never queue two phases concurrently. Never start a new phase from a stale branch. Phases too large for one PR (candy-vt) get split internally but each sub-PR runs through this same cleanup gate before the next sub-PR begins.

### Bundling rule for small PRs

Per the project's ship-as-you-go cadence and `feedback_pr_size` memory: bundle 2–4 related items per PR when they share a coherent scope, rather than one-feature-per-PR. For this document that mostly means within a phase — not across phases.

---

## §2. Current state inventory

### What we have (verified by direct inspection)

| Component | Where | Status |
|---|---|---|
| Cassette format | `candy-vcr/src/Cassette.php`, `CassetteHeader.php` | ✅ Solid |
| Cassette serializers | `candy-vcr/src/Format/` — Jsonl, Relative, Yaml, Asciinema, CompressedJsonl | ✅ 5 formats |
| Player | `candy-vcr/src/Player.php` | ✅ Speed modes, idle-trim |
| Recorder | `candy-vcr/src/Recorder.php` | ✅ |
| CLI scaffold | `candy-vcr/src/Cli/` — record, inspect, replay, diff, stats, migrate | ✅ 6 subcommands |
| PTY abstraction | `candy-pty/src/` | ✅ Reusable for shell exec |
| Program/Msg primitives | `candy-core/src/` | ✅ |

### What we don't have

| Component | Status | Effort |
|---|---|---|
| candy-vt terminal emulator | ❌ Only `CursorShape.php` exists | **2–3 weeks** |
| Tape lexer/parser/compiler | ❌ Not started | 1 week |
| Frame stream / dedup | ❌ Not started | 1 week |
| Rasterizer (gd / imagick) | ❌ Not started | 1–2 weeks |
| Theme system + ANSI palette | ❌ Not started | included in candy-vt |
| GIF encoder | ❌ Not started | ½ week |
| Bundled monospace TTF | ❌ Need to vendor JetBrainsMono or similar | ½ day |
| `render-tape` CLI subcommand | ❌ Not started | ½ week |
| candy-vcr PHPUnit suite green | ❌ Currently broken — `TickModel::subscriptions()` unimplemented | ½ day |

---

## §3. Target architecture

Names follow project conventions: `final` classes, `declare(strict_types=1)`, immutable + fluent, public `readonly` properties.

```
candy-vt/src/                                  (new — terminal-grid emulator)
  Terminal.php             — public surface; cell grid + cursor state + scroll region
  Cell.php                 — char + fg + bg + attrs (readonly)
  CellGrid.php             — 2D grid with diff & dirty-region tracking
  Cursor.php               — pos + shape + visibility
  Parser/
    Parser.php             — VT100/VT500/XTerm CSI parser (state machine)
    CsiHandler.php         — SGR, CUP, ED, EL, DECSET, scroll region, etc.
    OscHandler.php         — title, hyperlink, color queries
  Theme/
    Theme.php              — 256-color palette + 16 named slots + default fg/bg
    Themes.php             — bundled catalog (TokyoNight first; Dracula, Solarized later)
  Snapshot.php             — frozen frame: (CellGrid, Cursor, time)

candy-vcr/src/Tape/                            (new — tape DSL → events)
  Lexer.php                — line-oriented tokenizer
  Parser.php               — directives → AST nodes
  Ast/
    Directive.php          — base interface
    Type.php  Enter.php  Sleep.php  Set.php  Env.php  Ctrl.php
    Arrow.php  Backspace.php  Tab.php  Wait.php
  Compiler.php             — AST → cassette Event stream + CassetteHeader

candy-vcr/src/Render/                          (new — frame production)
  Renderer.php             — drives Terminal + emits Snapshots
  FrameStream.php          — iterable of Snapshots at fps cadence
  FrameDedup.php           — collapse identical adjacent frames

candy-vcr/src/Raster/                          (new — pixels)
  Rasterizer.php           — interface; Snapshot → image
  GdRasterizer.php         — ext-gd backend (default)
  ImagickRasterizer.php    — ext-imagick backend (optional, higher AA)
  FontLoader.php           — TTF resolver
  Glyphs.php               — per-(char, fg, bg, attrs) tile cache

candy-vcr/src/Encode/                          (new — GIF assembly)
  GifEncoder.php           — interface
  FfmpegGifEncoder.php     — shell to ffmpeg (default — ffmpeg already in CI)
  PhpGifEncoder.php        — pure-PHP fallback (no shell-out)

candy-vcr/src/Cli/RenderTapeCommand.php        (new CLI entrypoint)
candy-vcr/fonts/                               (new — bundled assets)
  JetBrainsMono-Regular.ttf
  JetBrainsMono-Bold.ttf
```

The existing `candy-vcr/src/Event.php` / `Cassette.php` / `Player.php` stay unchanged — the tape compiler produces events in the same shape the Player already consumes, so the orchestration layer is free.

---

## §4. Phase-by-phase build plan

Each phase ships independently; each ends with `composer install && vendor/bin/phpunit` on touched libs.

### Phase 0 — Unblock + tooling baseline (1 day)

This phase exists to make every later phase faster. Bundle into 1 PR.

- **Fix candy-vcr PHPUnit suite.** Implement the missing `TickModel::subscriptions()` (likely just `return null;`).
- **Add `candy-vcr/phpstan.neon`** at `level: max`, with paths `src/` and `tests/`. Fix any findings inline; if any can't be fixed without scope creep, add `// @phpstan-ignore-next-line` with an inline comment referencing this phase.
- **Add `candy-vt/phpstan.neon`** same shape (still mostly empty source tree but the gate is in place for Phase 1).
- **Add root `.php-cs-fixer.dist.php`** with PSR-12 + strict types + readonly preferences. Run it across `candy-core`, `candy-vcr`, `candy-vt` to seed the baseline (other libs adopt over time).
- **Baseline benchmark.** Run the existing Player on a medium cassette (~150 events) and capture wall time + memory. Establishes the per-event cost the render work will add to.
- **Decision log** committed to `candy-vcr/CALIBER_LEARNINGS.md`:
  - Primary rasterizer backend: `ext-gd` (universal availability).
  - Primary GIF encoder: `FfmpegGifEncoder` (ffmpeg already in CI image).
  - Font: JetBrainsMono Regular + Bold (OFL license, monospace, broad glyph coverage).
- **Documentation** (per protocol §5):
  - `candy-vcr/README.md` — note the new gates (phpstan + cs-fixer) under "Development".
  - Root `README.md` — note php-cs-fixer in the development section.

### Phase 1 — Build candy-vt terminal emulator (2–3 weeks)

The longest pole. candy-vt is its own self-contained foundation lib; the renderer is just its first consumer.

**Scope priorities (deliver smallest useful surface first):**

1. Value objects: `Cell` (char + fg + bg + attrs bitfield), `CellGrid` (2D array + dimensions + diff tracking), `Cursor` (row, col, shape, visible).
2. CSI parser as a state machine. States: GROUND, ESC, CSI_ENTRY, CSI_PARAM, CSI_INTERMEDIATE, CSI_IGNORE, OSC_STRING, DCS_*. Reference: ECMA-48 + xterm ctlseqs.
3. CSI handler dispatch table covering the minimum surface our directives need to render: `SGR` (colors + bold/italic/underline/inverse), `CUP` (cursor position), `CUU/CUD/CUF/CUB` (cursor up/down/forward/back), `ED` (erase display), `EL` (erase line), `DECSET/DECRST` (mode set/reset — including cursor visibility), scroll region (`DECSTBM`).
4. OSC handler: window title, hyperlinks (we may not need but document).
5. Theme: 16 ANSI palette slots + 256-color cube + default fg/bg. Bundle `Themes::tokyoNight()` first (the only theme used in 277 monorepo tapes). Add Dracula, Solarized, GruvBox, Catppuccin later.
6. `Snapshot::of(Terminal $t, float $time)`: immutable frozen frame.

**Test plan:**
- Golden-input tests: feed known byte sequences (`"\x1b[31mhello\x1b[0m"`, `"\x1b[2;5Hxyz"`, etc.), assert cell grid state.
- Snapshot tests: drive Terminal with output from real commands (captured separately — e.g., `ls --color`, `htop -bn1`); assert the rendered cells match a golden file.
- Theme test: every named color in TokyoNight resolves to its hex.

**Deliverable:** candy-vt v0.1 with public API: `Terminal::new(cols, rows, theme) → feed(bytes) → snapshot()`.

### Phase 2 — Tape lexer/parser/compiler (1 week)

**Lexer:** line-oriented; tokens are line-leading verbs plus arguments.
- `Output <path>`
- `Set <key> <value>` (key ∈ Theme, FontSize, Width, Height, TypingSpeed, FontFamily, Padding, Margin, etc.)
- `Type "..."`
- `Enter`, `Tab`, `Backspace`, `Up`, `Down`, `Left`, `Right`, `Space`, `Escape`
- `Ctrl+<letter>`
- `Sleep <duration>` (s, ms, m suffixes)
- `Env <KEY> "<value>"`
- `Hide`, `Show`, `Wait <duration> [Screen /regex/]`, `Screenshot <path>` — out of v1 scope but parse without error if encountered, with a `--strict` flag that opts into rejection.

**Parser:** recursive-descent or PCRE-multi-line; emits a `list<Directive>` AST. Location-marked errors. Validate `Set` keys against allowlist.

**Compiler:** walks the AST and produces a `Cassette` with:
- `CassetteHeader` carrying cols/rows (from `Set Width`/`Set Height` translated through font metrics), theme name, env map, fps.
- A timed stream of `Event` objects matching `candy-vcr/src/Event.php`. `Type "abc"` expands to three KeyMsg events spaced at `TypingSpeed` ms. `Enter` is a single CR event. `Sleep 1s` is a timing-only event that advances the clock without input.

**Regression corpus:** the 841 existing `.tape` files in the monorepo. Parse-coverage target: 100%. Any directive that fails to parse becomes a tracked gap.

**Test plan:**
- Unit tests per directive.
- Corpus test: walk every `.tape` file in the repo and assert it parses without error.
- Round-trip test: parse → compile → decompile → re-parse should be stable for canonical inputs.

### Phase 3 — Renderer (1 week)

`Renderer` orchestrates Player + Terminal:

- For each `Event` from the Cassette, feed its bytes to `Terminal`.
- Advance the virtual clock by the event's `dt`.
- At every `1/fps` boundary, capture a `Snapshot` from the Terminal.
- Hand snapshots downstream as an iterator.

`FrameDedup`: if `Snapshot::equals(previous)`, increment the previous frame's hold duration instead of emitting a duplicate. Critical for GIF size — upstream vhs does this and so must we (typical tape has 80-95% redundant frames).

Honor:
- `Set TypingSpeed` (default 50ms inter-keystroke).
- `Sleep` (real-time pause in the virtual clock).
- Arrow / Ctrl sequences emit the right CSI / control characters.

### Phase 4 — Rasterizer (1–2 weeks)

**GdRasterizer (default):**
- For each `Snapshot`, allocate an image at `(cols × cellW, rows × cellH)`.
- Walk cells; for each, look up glyph tile from `Glyphs` cache; blit at cell position.
- Render cursor block last if visible.
- Apply selection/underline/inverse decorations.

**FontLoader:**
- Resolve TTF from `candy-vcr/fonts/`.
- JetBrainsMono Regular + Bold are first-class.
- Italics: synthesize via slant if the italic font isn't bundled (or bundle JetBrainsMono-Italic too).

**Glyphs cache:**
- Per-`(char, fg, bg, bold, italic, underline)` tuple, pre-render an image tile and cache it for the rest of the render.
- A typical terminal frame has thousands of cells but only ~50 unique (char, attrs) combinations — caching converts the rasterization cost from `O(cells)` to `O(unique tiles + cell blits)`. Single biggest perf lever.

**Cell metrics:**
- Default 8×16 px at FontSize 14; scale linearly with `Set FontSize`.
- Document the calculation so users can predict GIF dimensions.

**UTF-8 / wide chars:**
- BMP characters render at 1 cell width.
- CJK / fullwidth render at 2 cell widths (use `mb_strwidth` + `Width::char()`).
- Emoji: deferred to v2. Document the limit.

**ImagickRasterizer (alternative):**
- Slightly better anti-aliasing; useful if visual drift from upstream becomes a complaint.
- Kept as an option, not the default.

### Phase 5 — GIF encoder (½ week)

**FfmpegGifEncoder (default):**
- Write frames as PNGs to a temp dir.
- Invoke ffmpeg with two-pass palette generation:
  ```
  ffmpeg -framerate 30 -i frame%05d.png \
    -vf "split[s0][s1];[s0]palettegen=stats_mode=diff[p];[s1][p]paletteuse=dither=bayer:bayer_scale=5" \
    -loop 0 out.gif
  ```
- Honor per-frame hold from FrameDedup via VFR input (`-fps_mode vfr`) or per-frame `duration` directives in a concat list.

**PhpGifEncoder (fallback):**
- Pure-PHP using `imagegif()` per frame + custom LZW for the animation chunks.
- Slow; exists only for environments without ffmpeg.

**Goal:** visual parity with upstream vhs at "looks the same at a glance", not byte parity. Document the expectation.

### Phase 6 — CLI integration (½ week)

`RenderTapeCommand`:
```
candy-vcr render-tape <tape-file>
    [--output <gif>]            # default: same path as input with .gif extension
    [--font <ttf>]              # default: bundled JetBrainsMono-Regular
    [--theme <name>]            # default: TokyoNight
    [--fps <n>]                 # default: 30
    [--backend gd|imagick]      # default: gd
    [--encoder ffmpeg|php]      # default: ffmpeg
    [--strict]                  # error on unknown directives instead of skipping
```

Wire into the existing Symfony Console app in `candy-vcr/src/Cli/`. Update `candy-vcr/bin/candy-vcr` only if entry-point changes are needed.

Add `render-batch <dir>` command that processes every `*.tape` under a directory in one PHP process, avoiding repeated PHP startup overhead for the CI case.

### Phase 7 — CI migration (1 week including soak)

**Container image:**
- New variant `ghcr.io/<org>/vhs-runner-php:latest`.
- Drops: `vhs` binary, `ttyd`, Chromium libraries.
- Keeps: `ffmpeg`, PHP 8.3 + ext-gd + ext-mbstring + ext-intl + ext-curl + ext-ssh2.
- Adds: bundled TTF files into the image (or rely on the lib's `fonts/` directory).
- Maintained by `.github/workflows/vhs-runner-image.yml`.

**Workflow changes:**
- New job `vhs-candy-vcr` runs in parallel with the existing `vhs` job on a seed lib (`candy-core` first). The job invokes `php candy-vcr/bin/candy-vcr render-batch <lib>/.vhs/`.
- After visual sign-off on the seed lib, expand to 5 more libs.
- After a 2-week soak with no regressions, the upstream `vhs "$tape"` line is replaced.
- The container-image swap is a separate PR.

**Rollback:** one-line revert in `.github/workflows/vhs.yml`. The upstream-vhs container variant lives for 2–3 releases as a safety net.

---

## §5. Performance budget

**Target:** ≤30 min wall-clock for the full 841-tape render (matches current ceiling under upstream vhs).

**Per-tape estimates (informed; will be measured during Phase 0 and Phase 4):**

| Step | Per-tape (median) | Notes |
|---|---|---|
| Tape parse | <50 ms | Trivial string parsing |
| Cassette compile | <50 ms | AST walk |
| Render (5s session × 30fps × dedup → ~50 unique frames) | 200–500 ms | Terminal feed + snapshot |
| Rasterize 50 frames at 800×480, glyph cache warm | 500 ms – 2 s | Dominated by glyph blits |
| GIF encode via ffmpeg + palettegen | 500 ms – 2 s | Two-pass palette overhead |
| **Per-tape total** | **1–5 s** | |
| 841 tapes serial | 14–70 min | Borderline |

**Parallelization saves it:** the existing CI matrix shards by lib (sugar-dash gets 4×). At ~15 effective shards, 841/15 ≈ 56 tapes per shard × 5 s ≈ 5 min — well inside the budget.

---

## §6. Performance bottlenecks to watch (with mitigations)

| Bottleneck | Where | Mitigation |
|---|---|---|
| CSI parser hot loop in pure PHP | candy-vt Parser | Profile after Phase 1; convert inner state machine to a switch-table; consider memoizing common SGR-only sequences. |
| Glyph rendering dominates rasterization | Raster/Rasterizer | The `Glyphs` cache **is** the mitigation — without it, expect 10× slower. Measure cache hit rate; aim for >95% on typical tapes. |
| ffmpeg `palettegen` runs per-frame by default | Encode/FfmpegGifEncoder | Use `palettegen=stats_mode=diff` once globally with `paletteuse=dither=bayer:bayer_scale=5`. |
| PHP startup overhead × 841 tapes | CLI invocation | `render-batch` command processes a whole directory in one PHP process. |
| Frame dedup defeated by cursor blink or animation | Render/FrameDedup | Cap blink rate to 1Hz; document that animated demos won't dedup well. |
| Memory growth from accumulated snapshots | Render/FrameStream | Iterator-based; never hold more than 2 snapshots (current + previous) in memory. |
| Temp PNG disk write/read for ffmpeg pipe | Encode/FfmpegGifEncoder | If disk I/O bites, pipe PNGs to ffmpeg stdin via `proc_open` instead of temp files. |
| ext-gd `imagettftext` per-glyph cost | Raster/GdRasterizer | Glyph cache solves the steady-state; the cold-cache cost on the first tape is unavoidable but small (~50 glyphs to warm). |

Performance work is gated on real numbers from Phase 0 and Phase 4 benchmarks, not premature optimization.

---

## §7. Tape directive coverage matrix (target for v1)

| Directive | Usage in 841 monorepo tapes | v1 status | Behavior |
|---|---|---|---|
| `Output <path>` | every tape | ✅ supported | Write GIF to path |
| `Set FontSize <n>` | 266 | ✅ supported | Scales cell metrics linearly |
| `Set Width <n>` / `Set Height <n>` | 201 | ✅ supported | Terminal cols/rows |
| `Set TypingSpeed <Xms>` | 101 | ✅ supported | Inter-keystroke delay during `Type` |
| `Set Theme "..."` | 277 | ✅ supported (TokyoNight first; others lazy-loaded) | Maps to candy-vt theme catalog |
| `Set Padding` / `Set Margin` | 0 | ⚠️ parsed, no-op | Cosmetic chrome; defer to v2 |
| `Set FontFamily` | 0 | ⚠️ parsed, no-op | v1 ships JetBrainsMono only |
| `Type "..."` | 311+ | ✅ supported | Each char as KeyMsg at TypingSpeed cadence |
| `Enter` `Tab` `Backspace` | 311+ | ✅ supported | Key Msg sequences |
| Arrow keys `Up`/`Down`/`Left`/`Right` | 16+ | ✅ supported | CSI sequences |
| `Ctrl+<letter>` | 9+ | ✅ supported | Control character |
| `Sleep <duration>` | widespread | ✅ supported | Real-time clock advance |
| `Env KEY "..."` | 1+ | ✅ supported | Exported into child env |
| `Hide` / `Show` | 0 | ⚠️ parsed, ignored | Frame-capture toggle; defer |
| `Wait /regex/` | 0 | ❌ not in v1 | Useful directive; v2 |
| `Screenshot <path>` | 0 | ❌ not in v1 | Single-frame capture; v2 |
| `Copy` / `Paste` | 0 | ❌ not in v1 | Clipboard; v2 |
| `Source <tape>` | 0 | ❌ not in v1 | Tape inclusion; v2 |
| `PlaybackSpeed` / `LoopOffset` | 0 | ❌ not in v1 | v2 |
| `ScrollUp` / `ScrollDown` / `Page*` | 0 | ❌ not in v1 | Need terminal scrollback first |
| `CursorBlink` | 0 | ❌ not in v1 | Defeats dedup; defer |

v1 covers everything actually used in the monorepo. v2 covers the remainder for parity with upstream vhs.

---

## §8. Test strategy

- **Unit tests per phase** live in the matching lib (`candy-vt/tests/`, `candy-vcr/tests/Tape/`, `candy-vcr/tests/Render/`, etc.).
- **Visual regression:** ~10 curated golden GIFs checked into the repo. CI re-renders and compares against goldens — file-hash for byte-deterministic builds; SSIM threshold if we need fuzziness.
- **Corpus parse test:** assert every `.tape` file in the monorepo parses without error. No rendering required — just lexer/parser/compiler coverage.
- **Smoke test:** `php candy-vcr/bin/candy-vcr render-tape candy-vt/.vhs/snapshot-demo.tape` must produce a non-empty GIF. Commit one canonical golden.
- **Performance smoke test:** `time render-batch` against a 10-tape directory; CI fails if wall time exceeds threshold.

---

## §9. Workflow migration playbook

1. **Phase 0 PR:** Fix candy-vcr PHPUnit; commit baseline benchmark to CALIBER_LEARNINGS.md.
2. **Phase 1 PRs (multiple):** candy-vt incremental — `Cell` + `CellGrid` + `Cursor`; then Parser/state-machine; then CSI handler; then Theme + Themes.
3. **Phase 2 PR:** Tape lexer/parser/compiler; corpus parse test passes.
4. **Phase 3 PR:** Renderer + FrameDedup.
5. **Phase 4 PRs:** GdRasterizer + Glyphs + FontLoader; bundle TTF.
6. **Phase 5 PR:** FfmpegGifEncoder; smoke test produces a viewable GIF.
7. **Phase 6 PR:** `render-tape` and `render-batch` CLI commands.
8. **Phase 7 PRs:** New runner-image variant; parallel `vhs-candy-vcr` workflow job on a seed lib; gradual expansion; soak; finally drop upstream-vhs.

Each PR ends with `vendor/bin/phpunit` per affected lib and (for Phase 7) a visual diff on the seed library's GIFs.

---

## §10. Risks and rollback

| Risk | Likelihood | Mitigation |
|---|---|---|
| Visual drift from upstream (font, theme palette, kerning) | High | Tune until "close enough"; document the expectation; offer `ImagickRasterizer` as a fallback if needed. |
| Performance overshoot (>30 min CI budget) | Medium | Phase 0 + Phase 4 benchmarks gate Phase 7; if budget breaks, expand matrix sharding before reverting. |
| candy-vt scope creep (terminal emulator is its own project) | High | Strict v1 scope: only what the renderer needs. Defer DECSAVE, alt-screen, mouse, BiDi to v2. |
| Hidden directives in newer tapes break parser | Low | Corpus parse test runs before render; `--strict` flag for opt-in rejection. |
| Determinism change (vhs is non-deterministic, candy-vcr is byte-deterministic) breaks `git diff --quiet` re-render check | Medium | Switch the re-render check to a manifest-hash comparison; deterministic output is actually an upgrade. |

**Rollback** is always a one-line workflow revert. The upstream-vhs runner image lives in parallel for 2–3 releases.

---

## §11. Alternative architectures considered (and why not)

- **Shell to headless Chromium** (mimics upstream vhs internals). Rejected — defeats the PHP-native ethos; re-introduces the Chromium container weight we'd be removing.
- **Use ImageMagick CLI for everything (no PHP image libs).** Rejected as the primary path — ffmpeg has stronger GIF tooling (`palettegen` + `paletteuse`) and is already in the image. Kept `ImagickRasterizer` as an alternative backend.
- **Pure-PHP everything (no ffmpeg shell-out).** Rejected as default — pure-PHP LZW encoding is 5–10× slower. Kept as `PhpGifEncoder` for environments where shelling out isn't allowed.

---

## §12. Improvements list — for candy-vcr itself (alongside the renderer build)

Independent of the render work; the existing recorder/player has room to grow:

- Fix the broken PHPUnit (`TickModel::subscriptions()`).
- Tape-format auto-detection (`.tape` vs cassette by extension and header sniff).
- Dry-run mode (`render-tape --dry-run` prints the compiled event stream without rendering).
- `inspect --frames` mode that lists the snapshot timeline.
- Document the existing cassette formats (Jsonl vs CompressedJsonl trade-offs) in the README.

---

## §13. PHP-specific opportunities

Things upstream Go can't easily do that PHP can leverage:

- **ReactPHP async loop** for parallel tape compilation + rendering within one process.
- **Distributed rendering** via Amp/promise pools — one batch process per CPU core.
- **Incremental re-rendering:** only re-render tapes whose mtime > corresponding GIF mtime. Bypass the full corpus on no-op PRs.
- **Live preview mode:** open a terminal window, watch as a tape renders frame-by-frame. Useful for tape authoring.

---

## §14. Effort summary

| Phase | Estimate | Critical path |
|---|---|---|
| 0 — Unblock | ½ day | yes |
| 1 — candy-vt | **2–3 weeks** | **yes (longest pole)** |
| 2 — Tape parser | 1 week | yes |
| 3 — Renderer | 1 week | yes |
| 4 — Rasterizer | 1–2 weeks | yes |
| 5 — GIF encoder | ½ week | yes |
| 6 — CLI | ½ week | yes |
| 7 — CI migration + soak | 1 week | yes |
| **Total** | **6–10 weeks focused work** | |

---

## §15. Verdict

**Yes, achievable.** The biggest hidden cost is building candy-vt itself, not the candy-vcr work. With ffmpeg already in CI and gd/imagick available in PHP, the PHP-native path is realistic without re-introducing the Chromium/ttyd container weight.

Recommended sequencing: ship Phase 0 first (cheap, unblocks measurement), then Phase 1 (the candy-vt base) as a long-running parallel workstream, then Phases 2–7 in order once Phase 1 has a usable Terminal API. Each phase ships as its own PR(s); the renderer is additive throughout — upstream vhs keeps running in CI until Phase 7's soak proves the candy-vcr path is reliable.

(End of file - total 554 lines)
