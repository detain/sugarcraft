# REVIEW-1 — cc/C-TUI-2.c @ 2b8bf855c (parent da284586a)

VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 MINOR.

## Gates re-derived at tip (all EXACT)
- `tests/Renderer/PaneWidthInvariantTest.php tests/RendererTest.php` → OK 320T/29,250A.
- Settings-guard sextet (SettingsSchemaDocDrift/ReadmeRosterDrift/ReadmeSettingsTierClaim/EnvRosterDrift/TrustKeyDocDrift/UiSettingsTest) → OK 105T/39,761A.
- Combined battery (DiffSplitRender + DiffGutter + config guards + DocFigureProseDrift) → OK 180T/6,104A.
- GlobDialect + LangParity → OK 20T/3,359A (no glob/refused-shaped literals).
- Base-reds: `SettingsEditorTest|KeyHelpTest` → exactly the 6 claimed foreign reds (KeyHelp×5 + searching-types); lane touches none of those surfaces. Worktree clean.

## Core judgments
1. **Width invariant is real**: sweep = 13 widths × 6 fixtures, 1,496 assertions, per-row `Width::string(Ansi::strip(row)) <= cols` on every frame row; force-split@40 and tabs@60 carry their own per-row loops. Not vacuous.
2. **Never-drop-row law exercised**: oversized-hunk fallback asserts `assertCount(15, content rows)` — a dropped row fails the pin; annotated fixture pins `\ No newline` stays full-width and unpaired. Split→fallback returns rows, never truncates the walk.
3. **Byte-identity (languageless)**: renderDiffBody returns the legacy `$style->render($row)` expression on every non-tokenized path (code-level identity), plus a negative-polarity pin (token SGR absent on every .txt row, present on the php fixture sanity leg). Acceptable; no assertSame-vs-legacy golden, see NITs.
4. **RendererTest in-step flips — both LEGITIMATE (§1.8)**: (a) edit-transcript needle now Ansi::strip'd (token SGR drift, colour re-pinned in marker-continuity test); (b) sql '--- users table, legacy' pin STRENGTHENED to exact SGR concat (keyword tint is grammar truth; deviation (iv)). Neither masks a regression.
5. **Setting plumbing parity**: Cosmetic/Layered/ProjectSettable/Live, withEnumValues auto|unified|split, reader symbol Renderer::diffStyle, rows in BOTH LAYERED_KEYS and PROJECT_TIER_KEYS, UiSettings::KEYS, gen-settings-doc outputs (README+SETTINGS.md) committed in-step, en.php 2 keys sorted soft-append, '120 columns or more' matches DIFF_SPLIT_MIN_COLS. Live semantics proven via HomeSandboxTrait per-config write + UiSettings::forget() real-path re-read.
6. **Perf**: no finding. Probe (shell `php` denied; measured via junit timings): steady-state split paint incl. full frame + tokenizing a 24-row hunk ≈ 2–3ms/paint (78-paint sweep = 79ms). The regex pattern cache is a process-static memo; first diff paint carries a one-time ~180ms (autoload + pattern compile) — disclosed as observation.
7. **Diff**: 11 files = exactly the block's named set; NO suite-figure.json / durations.tsv / dist (forbidden files clean). Conventions: strict_types, final, PSR-12, Lang::t, why-comments.

## NITs (no action)
- Split hunk-header row is painted without Width::truncate — bounded in practice (numberable starts ≤9 digits ⇒ header ≤~33ch < min inner ~35; production diffs come only from the internal sugar-diff generator).
- The spl_object_id seen-guard has no direct fixture (observed fallbacks are pinned via annotated/oversized instead); defense-in-depth.
- Byte-identity proven structurally + negatively, not by a legacy golden snapshot.

## Welder carries
- durations.tsv row for tests/Tui/DiffSplitRenderTest.php (~0.3s) or it runs in ZERO shards.
- +16T figure carry: suite-figure.json/README re-pin owed at weld; ReadmeSuiteFigureDrift staleness pair red until then (expected).
- LAYERED_KEYS/PROJECT_TIER_KEYS + README/SETTINGS.md generated rows: rebase-conflict expected vs C-TOOL-5.a / C-SES-6.b welds — resolve keeping BOTH key rows, then re-run gen-settings-doc --write and --check (the 133→134 roster numeral may shift again with sibling keys).
