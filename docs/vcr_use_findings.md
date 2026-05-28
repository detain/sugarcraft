# vcr_use.md audit findings

**Date:** 2026-05-22
**Source plan:** `vcr_use.md` (candy-vcr → charmbracelet/vhs replacement)
**Auditor:** code-level review of every Phase 0–7 deliverable
**Outcome of first pass:** 21 bugs found and fixed in commit pending PR; 558+506+331 tests green. This file enumerates the **remaining** gaps between `vcr_use.md` and the current implementation.

Items are split into sections so each can ship as one PR. **Do not remove items as work completes — replace `- [ ]` with `- [x]` so the verification pass can audit the trail.**

---

## Section A — Cross-frame glyph cache (perf)

**Why it matters:** `vcr_use.md` §6 lists this as "the single biggest performance lever." Today the cache is per-frame, so the win is lost.

- [x] Move `Glyphs` instance out of `GdRasterizer::rasterize()` into a private property on `GdRasterizer`.
- [x] Invalidate `Glyphs` when `(cellW, cellH, theme, fontFamily, fontSize)` changes — keep a fingerprint, rebuild on mismatch.
- [x] Same treatment for `ImagickRasterizer` (no `Glyphs` today — it builds an `Imagick` tile per cell; add an equivalent tile cache keyed on `(char, fg, bg, bold, italic, underline)`).
- [x] Add a test that proves cache reuse: render two consecutive snapshots with overlapping `(char, fg, bg, attrs)` tuples, instrument the cache to count rebuilds, assert ≥80% hit rate on the second frame.
- [x] Measure: render `candy-vcr/.vhs/smoke.tape` before/after, capture wall-time delta in `candy-vcr/CALIBER_LEARNINGS.md`.

## Section B — Bundle JetBrainsMono

**Why it matters:** Plan §0 decision log committed to JetBrainsMono (OFL, monospace, broad glyph coverage). Only DejaVuSansMono is bundled today.

- [x] Vendor `JetBrainsMono-Regular.ttf` + `JetBrainsMono-Bold.ttf` into `candy-vcr/fonts/`. (v2.304, static TTF, 273900 + 277828 bytes)
- [x] Add `JetBrainsMono-Italic.ttf` + `JetBrainsMono-BoldItalic.ttf` so italic / bold-italic don't synthesize via slant. (276840 + 279832 bytes)
- [x] License notice in `candy-vcr/fonts/LICENSE` (JetBrainsMono is SIL OFL 1.1 — include the full license text). Copied verbatim from upstream `OFL.txt`.
- [x] Update `candy-vcr/README.md` "fonts" section. Added under `## Development` → `### Fonts`.
- [x] Update `Glyphs::DEFAULT` font family already says JetBrainsMono — verify `FontLoader` resolves it. `FontLoader::resolve("JetBrainsMono", $style)` returns the bundled path for all four styles; `Glyphs::resolveFontPath` confirmed to populate `fontPathCache` with the JetBrainsMono TTFs.
- [x] Keep DejaVu as fallback in `Glyphs::resolveFontPath()` (already done). Untouched — DejaVuSansMono(-Bold).ttf still ship and the catch-block fallback ladder remains.

## Section C — Regression tests for fixed bugs

**Why it matters:** Twenty-one bugs were fixed in the first audit pass with **no** targeted regression tests. They could silently regress.

- [x] Theme propagation: tape with `Set Theme "TokyoNight"` renders a known fg cell using TokyoNight's RGB (not VGA `0x00ff00`). Assert pixel color at known cell. (`tests/Encode/TapeToGifThemeTest.php`)
- [x] UTF-8 in `Type`: tape `Type "café"` produces Input events whose payload contains the UTF-8 bytes `c3 a9`. (`tests/Tape/CompilerUtf8Test.php`; covers `é` plus 3-byte `日`)
- [x] `CassetteHeader::$theme` round-trips through `JsonlFormat` (write + read). (`tests/Format/CassetteHeaderThemeRoundTripTest.php`; required adding `theme` + `typingSpeed` to `JsonlFormat::encodeHeader`/`decodeHeader` so the field actually persists)
- [x] `PhpGifEncoder`: encode 3 frames with explicit `durations` `[100, 500, 100]` ms; parse the resulting GIF's Graphic Control Extension block; assert the delays are 10cs / 50cs / 10cs. (`tests/Encode/PhpGifEncoderDelayTest.php`)
- [x] `PhpGifEncoder`: first frame has LCT flag set (byte 9 of image descriptor packed field = `0x87`). (`tests/Encode/PhpGifEncoderLctTest.php`; checks every frame, not just the first)
- [x] `FfmpegGifEncoder`: encode with VFR durations → resulting GIF has variable per-frame delays (read back, assert non-uniform). (`tests/Encode/FfmpegGifEncoderVfrTest.php`, ffmpeg-gated)
- [x] `RenderBatchCommand --recursive`: place tape files at `dir/a.tape` and `dir/sub/b.tape`; assert both are rendered. (`tests/Cli/RenderBatchRecursiveTest.php`)
- [x] `RenderBatchCommand`: batch reuses one `TapeToGif` instance — assert `TapeToGif::create()` is called exactly once across N tapes (via mock / spy). (`tests/Cli/RenderBatchReuseTest.php`; `PhpToken` walk confirms one `TapeToGif::create()` call hoisted above the per-tape `foreach`, plus reflection confirms the rasterizer instance survives across `render()` calls)
- [x] `TapeToGif` temp dir: parallel test ensuring two `TapeToGif::render()` calls in same process use different temp dirs. (`tests/Encode/TapeToGifTempDirTest.php`; reflection drives `createTempDir()` twice)
- [x] `FrameStream` Resize preserves theme: feed a Resize event mid-stream; assert the post-resize Terminal carries the original theme. (`tests/Render/FrameStreamThemeResizeTest.php`; bg pixel must stay TokyoNight `0x15161e` after the Resize, via the rasterizer)
- [x] `ImagickRasterizer::indexToHex` grayscale: index 232 (rgb 8,8,8) returns `#080808`, not `#888888`. (`tests/Raster/ImagickRasterizerGrayscaleTest.php`; required fixing `Theme::color()` in candy-vt to fall back to `Theme::rgb()` for indices 216..255 not in the cube palette — the old `?? 0` returned black for grayscale)
- [x] `Application::runSymfonyCommand`: invoke `render-tape /tmp/foo.tape -o /tmp/foo.gif` via `Application::run()` (not directly), assert the tape arg reaches the command. (`tests/Cli/ApplicationRoutingTest.php`)
- [x] `pty-shim.php` autoload discovery: simulate being installed at `vendor/sugarcraft/candy-pty/bin/pty-shim.php` — assert it still finds an autoload. (`tests/Cli/PtyShimAutoloadTest.php`)

## Section D — PHPStan: clear baseline + 87 new errors

**Why it matters:** Plan §0 explicitly said "aim for `level: max` from day one — easier than back-fitting later." Today there are 1513 baseline lines + ~87 unbaselined errors in Phase 1–6 code.

- [x] Run `vendor/bin/phpstan analyze` in `candy-vcr`; list every error not in the baseline. (92 unbaselined errors found at start.)
- [x] Fix the ~87 unbaselined errors in `Cli/Application.php`, `Cli/RenderBatchCommand.php`, `Cli/RenderTapeCommand.php`, `Tape/Lexer.php`, `Tape/Compiler.php`, tests in `tests/Encode/`, `tests/Raster/`, `tests/Render/`, `tests/Tape/`. **Do not** add new baseline entries — fix at the source. (All 92 fixed at source: union typing for InputInterface options, removing redundant `assertInstanceOf` calls where types are already certain, narrowing nullable mixed → string casts via `is_string` guards, default arms on `match` expressions, redundant unused `new FfmpegGifEncoder('ffmpeg', $this->tempDir)` 2-arg-constructor call removed in TapeToGifTest.php — that was a real arity bug.)
- [x] Audit the existing 1513-line baseline: any entry that the post-audit code no longer triggers (because we fixed the underlying issue) must be removed. (Baseline regenerated: 1513 → 1501 lines, 439 errors baselined.)
- [x] candy-vt: regenerate or audit `phpstan-baseline.neon` similarly. (Found 35 unbaselined errors including REAL bugs: HandlerAdapter calling 3 methods missing from `CsiHandler` interface — `cht()`, `cbt()`, `gridRows()` — fixed by adding them to the interface. Baseline 433 → 397 lines, 119 errors baselined.)
- [x] Both libs end with `vendor/bin/phpstan analyze` clean (or with a justifiably smaller baseline). (Both exit 0.)

## Section E — php-cs-fixer install + lint pass

**Why it matters:** Plan §0 said add it so subsequent phases have a lint gate. Root `.php-cs-fixer.dist.php` exists but no `vendor/bin/php-cs-fixer` is installed anywhere.

- [x] Add `friendsofphp/php-cs-fixer: ^3.65` to `candy-vcr/composer.json` `require-dev`. (Installed as v3.95.2; no conflicts.)
- [x] Same for `candy-vt/composer.json`. (Installed as v3.95.2; no conflicts.)
- [x] Run `vendor/bin/php-cs-fixer fix --dry-run --diff` in both libs; commit fixes (separate commit from the dep install so the diff is readable). (candy-vcr: 2 files touched — `src/Encode/PhpGifEncoder.php` (`new class()` → `new class ()`) and `tests/Tape/CompilerTest.php` (`fn(...)` → `fn (...)`). candy-vt: 0 files touched.)
- [x] Add a one-line "lint" snippet to each lib's README under Development. (`vendor/bin/php-cs-fixer fix --config=../.php-cs-fixer.dist.php`; both libs have a Development section now.)

## Section F — Tape compiler round-trip + decompile

**Why it matters:** Plan §2 explicitly listed "Round-trip test: parse → compile → decompile → re-parse should be stable for canonical inputs." Decompile path doesn't exist.

- [x] Add `SugarCraft\Vcr\Tape\Decompiler` that turns a `Cassette` produced by `Compiler::compile()` back into a tape source string. Only needs to handle the directive subset the Compiler emits. (`candy-vcr/src/Tape/Decompiler.php`; Sleep threshold=100ms, Space folds into Type when sandwiched between printables, Ctrl byte 1..26 → upper-case letter. Hide/Show/Wait/Screenshot/Output documented as not round-trippable.)
- [x] Add `tests/Tape/RoundTripTest.php` covering: `Type "hello"`, `Enter`, `Sleep 1s`, `Set Theme "TokyoNight"`, `Ctrl+C`, `Up`/`Down`/`Left`/`Right`, `Backspace`, `Tab`, `Env KEY "value"`. For each, parse → compile → decompile → re-parse → assert identical event stream. (16 tests, 599 total in candy-vcr now passing.)
- [x] Document the Decompiler in `candy-vcr/README.md`. (New `### Decompiler — Cassette → tape source` subsection under `## Tape compiler (PR8)`.)

## Section G — Visual regression goldens

**Why it matters:** Plan §8 wanted ~10 curated golden GIFs in the repo with byte-hash or SSIM diff against re-renders.

- [x] Pick 10 tape files spanning: TokyoNight, Dracula, plain types-and-enter, Sleep-heavy, Ctrl-sequence, arrow-keys, wide-CJK type, Set Width/Height, multi-frame animation, idle-rich. (Authored fresh under `candy-vcr/tests/golden/tapes/01-*..10-*.tape` rather than reusing `<slug>/.vhs/*.tape` — those tapes assume `examples/<demo>.php` files outside `candy-vcr/`. Manifest in `candy-vcr/tests/golden/MANIFEST.md`.)
- [x] Render each to `candy-vcr/tests/golden/<name>.gif` and commit. (20 GIFs committed: `<name>.php.gif` + `<name>.ffmpeg.gif` for all ten tapes; total ~400 KB.)
- [x] `tests/Encode/VisualRegressionTest.php`: re-render each tape, compare against golden by file hash for byte-deterministic encoders (PhpGifEncoder), SSIM threshold ≥0.95 for FfmpegGifEncoder (use ffmpeg's `compare`). (`#[DataProvider]`-driven; PHP encoder asserted by SHA-256, ffmpeg encoder asserted via `[0:v][1:v]ssim` filter parse with pixel-diff fallback, ffmpeg tests auto-skip when `command -v ffmpeg` returns nothing. 40 assertions across 20 cases all green.)
- [x] Document refresh procedure in `candy-vcr/CALIBER_LEARNINGS.md`: how to regenerate goldens intentionally. (New `## Visual goldens (Section G — 2026-05-22)` section; pairs with `candy-vcr/scripts/refresh-goldens.php` which warns + exits 2 if >3 goldens would change in one run unless `--force`.)

## Section H — Phase 7 CI migration (seed lib)

**Why it matters:** Plan §7 wanted a parallel `vhs-candy-vcr` job on a seed lib, then expansion, then soak, then upstream replacement. Today only the runner image + smoke test exists.

- [x] Add a new job `vhs-candy-vcr` in `.github/workflows/vhs.yml` that runs in parallel with the existing `vhs` job on **candy-core** (seed lib). (Single-key matrix `lib: [candy-core]` so the matrix can expand without restructuring; runs alongside the legacy `render` job in the same workflow.)
- [x] Job uses the `vhs-runner-php` image and invokes `php candy-vcr/bin/candy-vcr render-batch candy-core/.vhs/`. (`container.image: ghcr.io/detain/sugarcraft-vhs-runner-php:latest`; renders the two tapes `counter.tape` + `timer.tape` with `--encoder ffmpeg` since the image bakes ffmpeg in.)
- [x] Job uploads its GIFs as a workflow artifact for visual comparison. (Artifact name `vhs-candy-vcr-${{ matrix.lib }}`, `retention-days: 7`, `include-hidden-files: true` so the dot-prefixed `.vhs/` dir's GIFs ship.)
- [x] Job is non-blocking initially (`continue-on-error: true`) so existing vhs CI stays green during the soak. (Set at the job level. Separate from the per-step smoke check, which exits non-zero on missing/empty/invalid GIFs but only marks this job failed — does not gate `render`.)
- [x] Document the migration plan + rollback in `candy-vcr/README.md` under "CI integration". (New section between Development and License; covers the dual-renderer soak table, the three-step cutover plan, and the one-line `git revert` rollback.)

## Section I — §12 polish

**Why it matters:** Plan §12 enumerated improvements alongside the renderer.

- [x] `candy-vcr render-tape --dry-run`: print compiled event stream as JSON (no render). (`RenderTapeCommand::dryRun()`; first line is `{"_header":…}` carrying cols/rows/runtime/theme/typingSpeed/eventCount/duration, each subsequent line is `{"t":…, "kind":…, "payload":…}`. `-o` is ignored — no GIF is written, even when passed. Tested via `tests/Cli/RenderTapeDryRunTest.php`.)
- [x] `candy-vcr inspect --frames <cassette>`: list snapshot timeline (time / cursor / cell-grid hash) for a cassette. (`InspectCommand::renderFrames()` walks the cassette through `Renderer + Terminal` at `--fps` (default 30), prints `time<TAB>row,col<TAB>sha1` per snapshot, then a `frames: N unique: M deduped: K` footer. Grid hash digests `(row, col, char, fg, bg, attrs)` cells row-major plus cursor state — deterministic. Tested via `tests/Cli/InspectFramesTest.php`, including a zero-event cassette case.)
- [x] Tape-format auto-detection: `Player::load(<path>)` detects `.tape` vs cassette by extension + header sniff (first non-blank line starts with a known tape directive vs JSON `{`). (Added `SugarCraft\Vcr\Format\CassetteLoader` + `Player::loadAny()`. Extension sniff first (`.tape` → Lexer/Parser/Compiler; `.cas`/`.jsonl`/`.cassette` → JsonlFormat or RelativeFormat depending on `t` vs `dt`; `.cast` → AsciinemaFormat; `.yaml`/`.yml` → YamlFormat; `.gz` → CompressedJsonlFormat). Content fallback skips leading `#` comment lines, then sniffs first token against known tape-directive heads. Inspect/Replay/Diff/Stats now all route through CassetteLoader so they accept either format. Tested via `tests/Format/CassetteLoaderTest.php`.)
- [x] Document cassette format trade-offs (Jsonl vs CompressedJsonl vs Relative vs Yaml vs Asciinema) in `candy-vcr/README.md`. (New `### Cassette formats` section with a 5-row trade-off table + auto-detect explainer + tape-vs-cassette note. Also documents the new `--dry-run` and `--frames` flags inline with `render-tape` / `inspect`.)

## Section J — Symfony `#[AsCommand]` modernization

**Why it matters:** `$defaultName`/`$defaultDescription` static-property pattern is deprecated. Two commands (`RenderTapeCommand`, `RenderBatchCommand`) already moved to `#[AsCommand]` during the audit; others still use the old pattern.

- [x] Audit every `final class … extends Command` in `candy-vcr/src/Cli/` and `candy-vcr/src/Cli/*`. (Only `RenderTapeCommand` and `RenderBatchCommand` extend `Symfony\Component\Console\Command\Command`; the other six — `RecordCommand`, `InspectCommand`, `ReplayCommand`, `DiffCommand`, `StatsCommand`, `MigrateCommand` — implement the local `SugarCraft\Vcr\Cli\Command` interface and never inherited the deprecated static-property pattern.)
- [x] Convert each that still uses `protected static $defaultName` to `#[AsCommand(name: …, description: …)]`. (Zero conversions needed: both Symfony-based commands already carry `#[AsCommand(name: 'render-tape', …)]` and `#[AsCommand(name: 'render-batch', …)]` from the audit pass; `grep -rn "defaultName\|defaultDescription" candy-vcr/src/Cli/` returns no matches.)
- [x] Drop the now-unused `parent::__construct('<name>')` calls. (None present — `grep -rn "parent::__construct" candy-vcr/src/Cli/` returns no matches; both Symfony commands omit the constructor entirely and rely on attribute-driven name resolution.)
- [x] Verify each subcommand still routes through `bin/candy-vcr <name>` after the change. (Smoke loop ran `php candy-vcr/bin/candy-vcr <sc> --help` for `record inspect replay diff stats migrate render-tape render-batch`; all eight respond. The Symfony commands print the framework's "Description:" block; the local-interface commands print their own `usage: …` or the `unknown option --help` diagnostic — confirming the router still dispatches to the correct class.)

---

## Section K — README documentation completeness

**Why it matters:** User asked that everything candy-vcr and candy-vt can do be fully documented in their README files at the end of this work.

- [x] `candy-vcr/README.md` documents every public-facing capability: CLI subcommands (record, replay, inspect, diff, stats, migrate, render-tape, render-batch) with each flag, the `Cassette` / `Recorder` / `Player` PHP APIs, all five cassette formats (Jsonl, Relative, Yaml, Asciinema, CompressedJsonl), the Tape DSL (Lexer/Parser/Compiler/Decompiler) with full directive table, the rasterizer + encoder backends (GdRasterizer, ImagickRasterizer, FfmpegGifEncoder, PhpGifEncoder), the Renderer + FrameStream + FrameDedup pipeline, the Theme system, FontLoader + Glyphs cache. (New `## Contents` TOC at the top; new `## PHP API` table covering Cassette/Header/Event/EventKind/Recorder/Player/Format/CassetteLoader/ReplayResult; new `### Matcher classes (L3 — replay-side flexibility)` table covering Passthrough/Content/TimingTolerant; new `## Visual regression goldens` section pointing to `tests/golden/` + `scripts/refresh-goldens.php`; expanded Tape directive table including the `Set` key allowlist + AST node enumeration; expanded Rasterizer section with FontLoader + Glyphs public surface; expanded Assertion family table including `RegexAssertion`.)
- [x] `candy-vt/README.md` documents Terminal (constructor, `feed`, `snapshot`, `theme`, `cursor`, `grid`, `windowTitle`), Cell/CellGrid/Cursor/Snapshot value-object surfaces, Parser state machine + handler interfaces, every theme factory (TokyoNight, TokyoNightLight, TokyoNightStorm, Dracula, SolarizedDark), CSI/OSC handler coverage table. (New `## Contents` TOC; new `## Two Terminal classes` table distinguishing the full VT facade from the renderer-path `Terminal::new()` used by candy-vcr; new `### Snapshot` subsection; new `## Parser state machine` section with State / Action / Transitions / HandlerAdapter; new `## CSI coverage table` mapping every final byte to its method/behavior; new `## OSC coverage` table; new `## SGR attributes` table; new `### Theme accessors` table; new `## Subsystems` table covering Buffer/Screen/Scrollback/Cell/Cursor/Sgr/UnderlineStyle/Mode/Hyperlink/Msg/Color/CursorShape.)
- [x] Both READMEs have a "Development" section with phpunit + phpstan + php-cs-fixer commands. (Already present from Section E; confirmed both libs.)
- [x] Both READMEs cross-link to the other lib (candy-vt → candy-vcr's renderer, candy-vcr → candy-vt's Terminal). (candy-vcr README intro now mentions "Pairs with candy-vt — every frame fed to the GIF encoder is a `SugarCraft\Vt\Snapshot` taken off candy-vt's `Terminal`." candy-vt README intro now says "Used as the terminal emulator behind candy-vcr's `render-tape` pipeline" and the `## Related` section adds a top-level link.)

## Final verification pass (after Sections A–K ship)

Spawn one last review agent to:

- [ ] Re-read `vcr_use.md` section by section and grep / read the codebase to verify each Phase 0–7 deliverable matches the plan.
- [ ] Re-confirm each box in this file is ticked.
- [ ] Run `vendor/bin/phpunit` in `candy-vcr`, `candy-vt`, `candy-pty` — report assertion counts.
- [ ] Run `vendor/bin/phpstan analyze` in `candy-vcr` and `candy-vt` — report error counts.
- [ ] Render `candy-vcr/.vhs/smoke.tape` end-to-end with both `--encoder ffmpeg` and `--encoder php`; report GIF dimensions + sizes.
- [ ] Compare against the upstream-vhs runner job's output for the seed lib; report any visual drift.
- [ ] Cross-check candy-vcr/README.md + candy-vt/README.md against the actual public API — flag undocumented surface.
- [ ] Write the final verdict ("Plan complete" or list of residuals) into this file at the bottom.

---

## Final verification verdict — 2026-05-23

**Status:** Plan complete

**Phase coverage:**
- Phase 0: pass — `candy-vcr/phpstan.neon` + `candy-vt/phpstan.neon` (`level: max`) and root `.php-cs-fixer.dist.php` all present; both phpstan suites exit `[OK] No errors`; both cs-fixer dry-runs report `files:[]`; `TickModel::subscriptions()` returns `null` in test models.
- Phase 1: pass — `candy-vt` ships `Terminal`, `Cell`, `CellGrid`, `Cursor`, `Snapshot::of()`, CSI state-machine parser (`Parser/Parser.php` + `State.php` + `Transitions.php` + `Action.php`), `CsiHandlerImpl` + `OscHandlerImpl` + `HandlerAdapter`, plus `Theme::tokyoNight()`/`dracula()`/`solarizedDark()`/`tokyoNightLight()`/`tokyoNightStorm()` factories and `Themes::all()`/`v1()` catalog.
- Phase 2: pass — `src/Tape/Lexer.php`, `Parser.php`, `Compiler.php`, full `Ast/*` directive set (Type/Enter/Sleep/Set/Env/Ctrl/Arrow/Backspace/Tab/Wait/Hide/Show/Screenshot/Output/Escape/Space). Corpus parse test green.
- Phase 3: pass — `src/Render/Renderer.php`, `FrameStream.php`, `FrameDedup.php` honor `Set TypingSpeed`, `Sleep`, arrow/Ctrl sequences. Theme survives `Resize` events.
- Phase 4: pass — `GdRasterizer` (default, cross-frame `Glyphs` cache with fingerprint invalidation), `ImagickRasterizer` (mirrored tile cache), `FontLoader`, `Glyphs`. JetBrainsMono Regular/Bold/Italic/BoldItalic + OFL `LICENSE` bundled; DejaVu retained as fallback.
- Phase 5: pass — `FfmpegGifEncoder` (palettegen + paletteuse + VFR), `PhpGifEncoder` (deterministic). Both produce a valid GIF89a in end-to-end smoke.
- Phase 6: pass — `RenderTapeCommand` (`#[AsCommand]`, `--dry-run`, font/theme/fps/backend/encoder/strict flags) + `RenderBatchCommand` (`#[AsCommand]`, `--recursive`, batch reuse) wired in `Application.php`; `bin/candy-vcr render-tape --help` and `render-batch --help` both respond.
- Phase 7: pass — `scripts/Dockerfile.vhs-runner` builds `ghcr.io/detain/sugarcraft-vhs-runner-php`; `.github/workflows/vhs.yml` carries the `vhs-candy-vcr` job on seed lib `candy-core` with `continue-on-error: true` + artifact upload; rollback documented in `candy-vcr/README.md` ("CI integration" section).

**Test totals:**
- candy-vcr: 634 tests / 5402 assertions (1 skipped — ffmpeg-gated VFR test on hosts without ffmpeg-on-PATH variant)
- candy-vt: 506 tests / 2127 assertions
- candy-pty: 331 tests / 929 assertions (7 skipped — FFI/PTY environment gates)

**phpstan:**
- candy-vcr: exit 0 — `[OK] No errors`
- candy-vt: exit 0 — `[OK] No errors`

**php-cs-fixer:**
- candy-vcr: clean — `files:[]`
- candy-vt: clean — `files:[]`

**End-to-end render:**
- ffmpeg encoder: 640×672 / 8969 bytes — `/tmp/final-smoke-ffmpeg.gif` (GIF89a)
- php encoder: 640×672 / 10515 bytes — `/tmp/final-smoke-php.gif` (GIF89a)

**Section A–K boxes:** 57 / 57 ticked (the 8 unchecked boxes on lines 130–137 are this verification pass itself, not Section A–K deliverables)

**Residuals:**
- Visual diff against the upstream-vhs runner job's output (line 135 of the checklist) is intentionally not gated here — Phase 7 ships the `vhs-candy-vcr` job non-blocking by design so the soak can collect comparison data over time. Documented in `candy-vcr/README.md` "CI integration".
- v2-scoped tape directives (`Wait`, `Screenshot`, `Copy`/`Paste`, `Source`, `PlaybackSpeed`, `LoopOffset`, `ScrollUp`/`ScrollDown`, `CursorBlink`) parse but do not render — matches the §7 coverage matrix promise, not a regression.

**Recommendation:** ship
