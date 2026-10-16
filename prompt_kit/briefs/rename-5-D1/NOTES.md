# rename-5-D1 NOTES — candy-mosaic → sugar-image <!-- @rename-keep -->

Lane: D1 (batch-D line 36, ns Mosaic→Image, type=lib, bins {}). Branch `rename/d1` off weld `8c30e631a`, worktree `/home/sites/sugarcraft-d1`. NEVER push master; push branch only. No R8/R9, NO remote rename (brief law — serial post-weld phase owns remotes; deviation from C4/C8 which ran R2 in-lane). <!-- @rename-keep -->

## Entry facts (verified)

- tools/renames.json line 36: kind=rename, candy-mosaic → **sugar-image**, lib, ns Mosaic→Image, bins {}, no extra_literals/protect, status pending. BRIEF LABEL DEVIATION: orchestrator brief said "sugar-compositor" — disk truth (renames.json + plan B.3:152 + Phase-6:1000) is `sugar-image`; proceeded per disk. Derived: pascal CandyMosaic→SugarImage, compact candymosaic/sugarimage, env CANDY_MOSAIC_/CANDYMOSAIC_ → SUGAR_IMAGE_ (ZERO sites tree-wide → honest none, no LegacyEnv; libs have no bin anyway). <!-- @rename-keep -->
- R6 Lang domain: candy-mosaic/src/Lang.php NAMESPACE='mosaic' → tool rewrites to 'image'. lang/en.php keys are bare. <!-- @rename-keep -->
- Pre-existence census: `SugarCraft\Image` 0 files, `SugarImage` 0, `sugar-image` dir/repo/page 0 — clean.
- Class-name precedent (C4 welded law): bare ns-name classes NOT renamed — `Mosaic.php`, `MosaicBuilder.php`, `ImageLayer.php` keep their class names under the new ns (sugar-overlay kept Veil.php/VeilStack.php). sugar-gallery/tests/MosaicPosterIntegrationTest.php name kept (same family).
- Consumer manifests requiring sugarcraft/candy-mosaic: sugar-crush, sugar-gallery, sugar-reel (+ root composer.json). Prose/docblock-only: candy-testing (KittyStream/Iterm2Stream/PixelGrid/Fixture/KittyImageTest), candy-pty, candy-core, candy-wish, candy-palette, sugar-reel-halfblock-test label `testDelegatesToCandyMosaic` (R2), AgentLabelFoldingTest (label fixtures R3), StdinConstantReaderCensusTest:390 roster path (R3 — live census, correct to rewrite), ImageRenderingTest/ToolResult docblocks. <!-- @rename-keep -->
- Baselines (rename-baseline.md, RE-MEASURE at base per brief law): candy-mosaic 661T/14820A/0F/0E/5S (:49); sugar-gallery 264/1162 (:75); sugar-reel 552/8489 (:81); crush pinned 22564/423652/2S. <!-- @rename-keep -->
- candy-mosaic/tests/bootstrap.php exists (LoopPin family per AGENTS.md) — suite is timer/child-aware (ChafaRendererChildTest proc_open; no child-lifetimes roster row for this lib — backlog:20705 erratum confirms). <!-- @rename-keep -->
- Wiring: codecov.yml name/paths/component ×6 (R3), .github/workflows/vhs.yml `all=…` :151 + `lib:` matrix :225 (R3), media/icons/candy-mosaic.png (C.2 path), ansi/candy-mosaic/ ×187 art (paths R3-auto, CONTENT excluded → wordmark bytes stale → followups), prompt_kit/tools/crush-dep-surface.php:26 `'candy-mosaic' => 'Mosaic'` — key R3-auto, VALUE bare 'Mosaic' is a MANUAL sweep (C4 'Veil'→'Overlay' precedent). <!-- @rename-keep -->

## Self-lock law (verified in rename-lib.php:246-248, keepsItsName :736)

A sentinel ANYWHERE in a file skips its ENTIRE path plan. NEVER tag: candy-mosaic/** (all files, incl README/ CALIBER / phpunit.xml), findings/candy-mosaic.md, findings/plan_candy-mosaic.md, docs/research/libraries/candy-mosaic-research.md, docs/repo_map/sugarcraft_candy-mosaic.md, docs/repo_map/update_sugarcraft_candy-mosaic.md, docs/_data/candy-mosaic.{json,body.html}, docs/lib/candy-mosaic.html, media/icons (binary anyway), ansi/candy-mosaic/**. README Formerly notice is added POST-move to sugar-image/README.md (harmless — path has no matching segment). <!-- @rename-keep -->

## Tag-prep set (history → @rename-keep; per-line)

- prompt_kit/CONTEXT.md ×16, prompt_kit/briefs/rename-baseline.md:49, prompt_kit/briefs/rename-1.3/NOTES.md, prompt_kit/findings/crush-report/15c-audit-tools-permissions.md, prompt_kit/findings/crush-dep-surface.txt
- docs/plans/crush_code_{hardening_backlog:40+20705-MOSAIC-line,worklog:39,backlog_triage:6,concurrency}
- findings/{monorepo-audit-2026-05-27:10,improvements-2026-05:1,resume_prompt:5}
- docs/plans/leftover/updates.md:9
- JUDGED-REWRITE (living docs, tool handles): docs/plans/{candy-flip-mosaic-split,candy-vt-graphics,candy-git-server-tui,candy-mail-identity,UPSTREAM_OPPORTUNITIES,README,leftover_updates,leftover_updates_later}.md, docs/plans/leftover/phase-*/step-*.md + _templates, findings/{plan_sugar-gallery,plan,findings_resume_plan,repeated_logic,sugar-reel}.md, docs/repo_map/** (living incl sugarcraft_super-candy.md), docs/research/**, AGENTS.md, README.md, docs/MATCHUPS.md, all PHP/code, codecov.yml, vhs.yml, plan_crush_media.md.
- docs/old/** excluded by C.3 — no tags.

## Steps

- tag-prep commit: 118 lines / 13 files tagged `@rename-keep historical provenance` (CONTEXT ×4 untagged-historical [996,1007,1352,1358], rename-baseline:49, rename-1.3 NOTES ×1, 15c-audit-tools-permissions ×1, crush-dep-surface.txt ×1, crush_code backlog ×43/worklog ×39/triage ×5/concurrency ×1, monorepo-audit ×10, resume_prompt ×3, leftover/updates.md ×9; improvements-2026-05 already tagged). JUDGED-REWRITE per ledger at head. R0 measured at base: mosaic 661T/14817A/6S (record 661/14820/5S — drift disclosed), gallery 264/1162 EXACT, reel 552/8488/1S (record -1A/+1S drift).
- (pending) R1 dry-run:
- (pending) R2 remote rename: SKIPPED per brief (no remote renames in lanes)
- (pending) R3 tool run:
- (pending) R4 manual:
- (pending) R5 wiring:
- (pending) R6 gates:
- (pending) R7 commit:
