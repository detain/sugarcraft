# B5 sugar-glow → candy-mdview — execution notes <!-- @rename-keep -->

Entry: tools/renames.json batch-B line 12. kind=rename, type=app, ns Glow→MdView,
bins bin/sugarglow→bin/candy-mdview (GLUED compact bin spelling). <!-- @rename-keep -->
Base: master 7002ff6d4 == origin; tag-prep commit 40155b129. <!-- @rename-keep -->

## R0 recon findings
- Baseline (rename-baseline.md:76): 122T/313A/0F/0E/0S/0W — tagged @rename-keep pre-R1.
- Sibling dirs/packages prefixed `sugar-glow`: NONE. PROTECT auto-derivation inert; reverse-direction
  note for closeout: any FUTURE candy-mdview-* entry must PROTECT candy-mdview.
- 89 tracked files outside lib carry the slug; tools handle plan_rename.md, tools/*, docs/old/**,
  composer.lock (basename-excluded), .ansi content (C.3), binary ext, vendor — all inert to rewrite.
- Composer REQUIRE consumers: root composer.json:88 only. NO sibling lib requires sugarcraft/sugar-glow.
  Docblock-only mentions: candy-core/src/Util/TtyDetect.php:23, candy-shine/src/StyleGuide.php:22,
  sugar-readline/src/Highlight.php (:10,:13,:22 incl github URL), candy-git CALIBER+DiffHighlighter,
  tools/check-path-repos.php:21 comment. R1/R3 rewrite these mechanically — consumers' suites re-run anyway.
- Env: SUGARGLOW_BIN appears ONLY in examples/render-readme.sh (test/example-only). Plan :947-948 ruling
  confirmed by reading where it is read: rewrite to CANDY_MDVIEW_BIN via tool R5, NO LegacyEnv shim.
  src getenv: NO_COLOR/CLICOLOR only (standard, not brand).
- User data paths: NO XDG/.config/.cache literals in src/bin/tests (grep empty) → LegacyPaths: honest none.
- ansi/sugar-glow/: does NOT exist. 4 ecosystem-banner tiles ansi/sugarcraft/*.ansi each spell the old
  wordmark once (content-excluded C.3) → followups redraw line. No ansi.json key for the lib.
- .vhs/ exists: pager.tape + render.tape (+gifs). Tapes use ./bin/sugarglow → R4 rewrites to
  ./bin/candy-mdview (valid). GIFs stale-brand: record, CI re-renders, per R4.10/B4 precedent.
- Lang: src/Lang.php NAMESPACE='glow' → tool R6 rewrites to 'mdview'. LangTest.php:21 hard-asserts
  literal 'glow' — outside R6 scope → MANUAL fix (R4-6) + method testNamespaceConstantIsGlow rename.
  Lang key domains render.*/glamour.* carry no brand. lang/*.php headers mention slug → R3 rewrites.
- BRAND CLASS (R4-7): `GlowModel` (src/GlowModel.php, final class implements Model) is brand-derived —
  tool vocabulary does NOT touch bare-ns compounds (residue R1 only fires on `SugarCraft\Glow\` form).
  MANUAL: GlowModel→MdViewModel; files src/GlowModel.php, tests/GlowModelTest.php,
  tests/GlowModelReloadTest.php → MdView*; fixtures glowmodel-{plain,styled}.golden → mdviewmodel-*;
  refs in RenderCommand (buildPagerModel/armPagerWatch), ReloadTickMsg docblock, CALIBER, GoldenRenderTest
  literal paths + testGlowModelWith* method names, WidthPlumbingTest::testNoGlowLocalWidthHelperIsResurrected
  → testNoMdViewLocal… + prose glow-local→mdview-local, "under the Glow namespace"→MdView.
  Precedents: B1 kept Stash* (domain noun = git stash); B4 left zero brand classes. `GlamourTheme` STAYS
  (upstream charmbracelet/glamour homage, like kept glamour.* lang keys). `pattern:glow` tags STAY
  (findings docs are historical, tooling never keys on pattern tags, not residue).
- Glued blindspot sweep (content `_sugar-glow`, tool R3 blocked, residue blind too):
  plan_sugar-glow.md refs — findings/findings_resume_plan.md ×2, findings/plan.md ×1,
  tests/GlowModelTest.php ×7 (@see lines). Path TARGETS (pathContext relaxes `_`) ARE renamed by tool:
  findings/plan_sugar-glow.md, findings/sugar-glow.md, docs/repo_map/{sugarcraft,update_sugarcraft}_sugar-glow.md,
  docs/_data pair, docs/lib page, docs/research/libraries/sugar-glow-research.md, media/icons + docs/img/icons png.
  No content refs to the _sugar-glow repo_map filenames exist (verified grep-empty).
  `_sugarglow`/`sugar_glow`/`_SUGARGLOW`: zero hits.
- MATCHUPS row already under `## Apps` @77 → R4-8 no section move. PROJECT_NAMES :84/:199 → R2+R3 rewrite.
- Generic-word "glow" landscape: zero hits inside themes/*.json + lang (no theme named glow); upstream
  charmbracelet/glow URLs/keywords everywhere → must SURVIVE untouched (R2/R3/R4 never fire on bare `glow`).
  Audit method (B4): scan git diff changed lines for glow→mdview corruptions; sample-verify survivors.
- mdview collision: zero pre-existing `MdView`/`mdview` tokens outside plan/tools (verified).
- oc.7z foreign 413MB → /tmp/oc.7z.b5-hold (same fs, instant); RESTORE at close.

## R1 dry-run
(pending)

## R2 remote rename
(pending)

## R3 tool run
(pending)

## R4 manual followups
(pending)

## R5 wiring
(pending)

## R6 verification
(pending)

## R7 commit
(pending)

## R8 push + remote-done
(pending)

## R9 packagist
(pending)

## Tracker
(pending)
