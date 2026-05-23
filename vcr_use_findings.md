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

- [ ] Add `SugarCraft\Vcr\Tape\Decompiler` that turns a `Cassette` produced by `Compiler::compile()` back into a tape source string. Only needs to handle the directive subset the Compiler emits.
- [ ] Add `tests/Tape/RoundTripTest.php` covering: `Type "hello"`, `Enter`, `Sleep 1s`, `Set Theme "TokyoNight"`, `Ctrl+C`, `Up`/`Down`/`Left`/`Right`, `Backspace`, `Tab`, `Env KEY "value"`. For each, parse → compile → decompile → re-parse → assert identical event stream.
- [ ] Document the Decompiler in `candy-vcr/README.md`.

## Section G — Visual regression goldens

**Why it matters:** Plan §8 wanted ~10 curated golden GIFs in the repo with byte-hash or SSIM diff against re-renders.

- [ ] Pick 10 tape files spanning: TokyoNight, Dracula, plain types-and-enter, Sleep-heavy, Ctrl-sequence, arrow-keys, wide-CJK type, Set Width/Height, multi-frame animation, idle-rich. Use existing `.vhs/*.tape` where possible.
- [ ] Render each to `candy-vcr/tests/golden/<name>.gif` and commit.
- [ ] `tests/Encode/VisualRegressionTest.php`: re-render each tape, compare against golden by file hash for byte-deterministic encoders (PhpGifEncoder), SSIM threshold ≥0.95 for FfmpegGifEncoder (use ffmpeg's `compare`).
- [ ] Document refresh procedure in `candy-vcr/CALIBER_LEARNINGS.md`: how to regenerate goldens intentionally.

## Section H — Phase 7 CI migration (seed lib)

**Why it matters:** Plan §7 wanted a parallel `vhs-candy-vcr` job on a seed lib, then expansion, then soak, then upstream replacement. Today only the runner image + smoke test exists.

- [ ] Add a new job `vhs-candy-vcr` in `.github/workflows/vhs.yml` that runs in parallel with the existing `vhs` job on **candy-core** (seed lib).
- [ ] Job uses the `vhs-runner-php` image and invokes `php candy-vcr/bin/candy-vcr render-batch candy-core/.vhs/`.
- [ ] Job uploads its GIFs as a workflow artifact for visual comparison.
- [ ] Job is non-blocking initially (`continue-on-error: true`) so existing vhs CI stays green during the soak.
- [ ] Document the migration plan + rollback in `candy-vcr/README.md` under "CI integration".

## Section I — §12 polish

**Why it matters:** Plan §12 enumerated improvements alongside the renderer.

- [ ] `candy-vcr render-tape --dry-run`: print compiled event stream as JSON (no render).
- [ ] `candy-vcr inspect --frames <cassette>`: list snapshot timeline (time / cursor / cell-grid hash) for a cassette.
- [ ] Tape-format auto-detection: `Player::load(<path>)` detects `.tape` vs cassette by extension + header sniff (first non-blank line starts with a known tape directive vs JSON `{`).
- [ ] Document cassette format trade-offs (Jsonl vs CompressedJsonl vs Relative vs Yaml vs Asciinema) in `candy-vcr/README.md`.

## Section J — Symfony `#[AsCommand]` modernization

**Why it matters:** `$defaultName`/`$defaultDescription` static-property pattern is deprecated. Two commands (`RenderTapeCommand`, `RenderBatchCommand`) already moved to `#[AsCommand]` during the audit; others still use the old pattern.

- [ ] Audit every `final class … extends Command` in `candy-vcr/src/Cli/` and `candy-vcr/src/Cli/*`.
- [ ] Convert each that still uses `protected static $defaultName` to `#[AsCommand(name: …, description: …)]`.
- [ ] Drop the now-unused `parent::__construct('<name>')` calls.
- [ ] Verify each subcommand still routes through `bin/candy-vcr <name>` after the change.

---

## Section K — README documentation completeness

**Why it matters:** User asked that everything candy-vcr and candy-vt can do be fully documented in their README files at the end of this work.

- [ ] `candy-vcr/README.md` documents every public-facing capability: CLI subcommands (record, replay, inspect, diff, stats, migrate, render-tape, render-batch) with each flag, the `Cassette` / `Recorder` / `Player` PHP APIs, all five cassette formats (Jsonl, Relative, Yaml, Asciinema, CompressedJsonl), the Tape DSL (Lexer/Parser/Compiler/Decompiler) with full directive table, the rasterizer + encoder backends (GdRasterizer, ImagickRasterizer, FfmpegGifEncoder, PhpGifEncoder), the Renderer + FrameStream + FrameDedup pipeline, the Theme system, FontLoader + Glyphs cache.
- [ ] `candy-vt/README.md` documents Terminal (constructor, `feed`, `snapshot`, `theme`, `cursor`, `grid`, `windowTitle`), Cell/CellGrid/Cursor/Snapshot value-object surfaces, Parser state machine + handler interfaces, every theme factory (TokyoNight, TokyoNightLight, TokyoNightStorm, Dracula, SolarizedDark), CSI/OSC handler coverage table.
- [ ] Both READMEs have a "Development" section with phpunit + phpstan + php-cs-fixer commands.
- [ ] Both READMEs cross-link to the other lib (candy-vt → candy-vcr's renderer, candy-vcr → candy-vt's Terminal).

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
