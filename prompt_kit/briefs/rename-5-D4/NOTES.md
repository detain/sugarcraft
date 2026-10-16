# rename-5-D4 NOTES — candy-flip → sugar-animation <!-- @rename-keep -->

Lane: D4 (batch-D line 38, ns Flip→Animation, type=lib, bins {}). Branch `rename/d4` off weld `55fb3bb93`, worktree `/home/sites/sugarcraft-d4`. NEVER push master; push branch only. No R8/R9, NO remote/packagist ops (lane law per D1; weld/serial phase owns remotes). <!-- @rename-keep -->

## Entry facts (verified)

- tools/renames.json line 38: kind=rename, candy-flip → sugar-animation, lib, ns Flip→Animation, bins {}, no extra_literals/protect, status pending. Derived: pascal CandyFlip→SugarAnimation, compact candyflip (ZERO sites), env CANDY_FLIP_/CANDYFLIP_ (ZERO sites → honest none, no LegacyEnv shim; lib has no bin). R6 Lang domain: candy-flip/src/Lang.php NAMESPACE='flip' → 'animation' (tool). <!-- @rename-keep -->
- Phase 1.3 already split the viewer out: lib has NO bin/ (viewer lives in candy-gifview, keeps its name, requires sugarcraft/sugar-animation → rewritten by R3).
- Pre-existence census: `sugar-animation`/`SugarAnimation`/`sugaranimation`/`SUGAR_ANIMATION_`/`SugarCraft\Animation` — ZERO tree-wide except plan_rename.md + renames.json (the maps themselves, excluded). Clean.
- Consumer manifests requiring sugarcraft/candy-flip: candy-gifview, candy-mosaic, candy-top, sugar-reel (+ root composer.json). sugar-crush has ZERO flip/ns/manifest refs (only English-word "Flip" identifiers — untouched by rules). Prose-only hits: candy-core DescriptorSinkArgumentCensusTest (roster path R3-auto), candy-pty PosixMasterPty docblock, ansi/sugarcraft art (content-excluded → followups). <!-- @rename-keep -->
- Baselines: rename-baseline.md:35 records 196T/903A (era of logo campaign); RE-MEASURED at base 55fb3bb93: **flip 191T/887A OK** (-5T/-16A drift disclosed, pre-existing, not chased), **gifview 16T/71A OK** (no record — minted). top recorded 2110/107777 (stale per C8 note); mosaic/reel records :49/:81 belong to their own renamed lanes — measure consumers at MY base below.
- sugar-animation has NO ansi/<slug>/ art dir; wordmark art exists only in ansi/sugarcraft/sugarcraft-3-16-l-2.ansi (*.ansi content excluded per C.4 → followups). .assets/play.tape carries no flip tokens. media/icons/sugar-animation.png (binary path auto-rename).
- vhs.yml `all=` :151 + matrix :225 (R3-auto), codecov.yml ×6 (R3-auto).
- Lang domain pin sites EXTERNAL to sugar-animation: ZERO (grep `'flip'` = only inside lib). Internal manual set: src/Lang.php docblock `'flip' namespace`, tests/LangTest.php fallback assert `'flip.nonexistent.key'` + comment + method `testNamespaceIsFlip` — must ride R6 in-step.

## Alias/brand blindspot map (pre-planned from D1 `as .*Flip[A-Z]` law)

- `use SugarCraft\Animation\Decoder as FlipDecoder;` / `Frame as FlipFrame;` — candy-mosaic/src/ImageSource.php (+2 tests) and sugar-reel/src/Decode/GifDecoder.php + tests/GifDecoderTest.php. R1 rewrites the alias TARGET, never the alias NAME. Manual: FlipDecoder→AnimationDecoder, FlipFrame→AnimationFrame (+ docblock/`{@see}` sites + reel private `$flipFrame`/`flipFrameToRgbFrame` → `$animationFrame`/`animationFrameToRgbFrame`).
- KEPT per C4 bare-brand precedent (no rule matches, judgment keep): class-bearing `Flip` words in mosaic test names `ImageSourceGifFlipWalkParityTest` + method names, candy-top English-verb test names (`testProfileFlipRepicks…`, `testTtyModeFlipLoads…`, NetPanel/OptionsMenu Flips*), crush Compress*Test "FlipABlock" English, `Frame::flip*` if any (checked: none).
- Glued content pointers (R3 lookbehind blocks `_candy-flip`): `findings/plan_candy-flip.md` ×3 (findings_resume_plan.md), `docs/repo_map/sugarcraft_candy-flip.md` ×3 in update_sugarcraft_candy-flip.md + update_sugarcraft_candy-mosaic.md, self-file pointers. Paths auto (C.2), pointer text manual.
- CAP-HYPHEN `Candy-Flip`/`Candy-flip`: ZERO (grep verified). BARE Pascal `SugarAnimation`: 26 files, all R2-auto (docs/_data, MATCHUPS, PROJECT_NAMES, README, search-index, lib itself).

## Self-lock law

NEVER tag @rename-keep in: candy-flip/** (all, incl README/CALIBER/phpunit.xml/lang), findings/{candy-flip,plan_candy-flip}.md, docs/research/libraries/candy-flip-research.md, docs/repo_map/{sugarcraft_candy-flip,update_sugarcraft_candy-flip}.md, docs/_data/candy-flip.{json,body.html}, docs/lib/candy-flip.html, media/icons. README Formerly notice added POST-move to sugar-animation/README.md. <!-- @rename-keep -->

## Tag-prep set (history → @rename-keep, per-line) — 61 lines / 12 files

crush_code_hardening_backlog ×6, worklog ×19, backlog_triage ×1, leftover/updates.md ×5, monorepo-audit ×5, improvements-2026-05 ×1, resume_prompt ×2, plan_crush_media ×5 (media-campaign historical inventory lines), sibling NOTES rename-1.3 ×13 / rename-2.5 ×1 / rename-remediation ×2, rename-baseline.md:35. CONTEXT.md ×6 flip lines ALREADY tagged (verified 0 untagged). crush-dep-surface.php has NO flip row (verified absent). JUDGED-REWRITE (living): docs/plans/sugar-animation-mosaic-split.md, leftover phase-09 step-01/02/03-flip-*.md + phase-11 step-04 + phase-01/07 steps + leftover README/_templates/README, repo_map inventories, research docs, MATCHUPS/PROJECT_NAMES/README/CLAUDE/index.html/search-index/sitemap, all PHP/code, yml, docs/old excluded per C.3.

## Steps

- (pending) tag-prep commit:
- (pending) R1 dry-run:
- (pending) R2 remote: SKIPPED per lane law.
- (pending) R3 tool run:
- (pending) R4 manual sweeps:
- (pending) R5 wiring:
- (pending) R6 gates (committed tip):
- (pending) R7 commits + push rename/d4:
