# B5 sugar-glow → candy-mdview — execution notes <!-- @rename-keep -->

Entry: tools/renames.json batch-B line 12. kind=rename, type=app, ns Glow→MdView,
bins bin/sugarglow→bin/candy-mdview (GLUED compact bin spelling). <!-- @rename-keep -->
Base: master 7002ff6d4 == origin; tag-prep commit 40155b129. <!-- @rename-keep -->

## R0 recon findings
- Baseline (rename-baseline.md:76): 122T/313A/0F/0E/0S/0W — tagged @rename-keep pre-R1.
- Sibling dirs/packages prefixed `sugar-glow`: NONE (tree dirs: only `sugar-glow/` itself). <!-- @rename-keep -->
  PROTECT auto-derivation inert; reverse-direction note for closeout: any FUTURE candy-mdview-*
  entry must PROTECT candy-mdview.
- 89 tracked files outside lib carry the slug; tools handle plan_rename.md, tools/*, docs/old/**,
  composer.lock (basename-excluded), .ansi content (C.3), binary ext, vendor — all inert to rewrite.
- Composer REQUIRE consumers: root composer.json:88 only. NO sibling lib requires sugarcraft/sugar-glow. <!-- @rename-keep -->
  Docblock-only mentions: candy-core/src/Util/TtyDetect.php:23, candy-shine/src/StyleGuide.php:22,
  sugar-readline/src/Highlight.php (:10,:13,:22 incl github URL), candy-git CALIBER+DiffHighlighter,
  tools/check-path-repos.php:21 comment. Tool rewrites these mechanically — consumers' suites re-run anyway.
- Env: `SUGARGLOW_BIN` appeared ONLY in examples/render-readme.sh (test/example-only) → tool R5 <!-- @rename-keep -->
  rewrote it to CANDY_MDVIEW_BIN, NO LegacyEnv shim (plan ruling confirmed by reading where it is read).
  src getenv: NO_COLOR/CLICOLOR only (standard, not brand).
- User data paths: NO XDG/.config/.cache literals in src/bin/tests (grep empty) → LegacyPaths: honest none.
- ansi/sugar-glow/: does NOT exist. 4 ecosystem-banner tiles ansi/sugarcraft/*.ansi each spell the old <!-- @rename-keep -->
  wordmark once (content-excluded C.3) → followups redraw line recorded. No ansi.json key for the lib.
- .vhs/ exists: pager.tape + render.tape (+gifs). Tapes drive ./bin/sugarglow → tool rewrote to <!-- @rename-keep -->
  ./bin/candy-mdview (valid). GIFs stale-brand: recorded in followups, CI re-renders, per R4.10/B4 precedent.
- Lang: src/Lang.php NAMESPACE='glow' → 'mdview' (tool R6). LangTest literal 'glow' assert + method name <!-- @rename-keep -->
  + synthetic key — MANUAL fix (R4e below). Lang key domains render.*/glamour.* carry no brand; glamour.*
  keys STAY (upstream charmbracelet/glamour homage). lang/*.php header slug mentions rewritten by tool. <!-- @rename-keep -->
- BRAND CLASS (R4-7): `GlowModel` (final class implements Model) was brand-derived — tool vocabulary does <!-- @rename-keep -->
  NOT touch bare-ns compounds. MANUAL rename GlowModel→MdViewModel (see R4a). Precedents: B1 kept Stash*
  (domain noun = git stash); B4 left zero brand classes. `GlamourTheme` STAYS (upstream homage).
  `pattern:glow` tags in findings docs STAY — historical findings key, no tooling keys on pattern tags, <!-- @rename-keep -->
  not residue (residue checker only hunts the slug forms).
- Glued blindspot sweep (content `_sugar-glow` / `sugarglow`, tool blocked, residue blind too): <!-- @rename-keep -->
  plan_sugar-glow.md CONTENT refs — findings/findings_resume_plan.md ×2, findings/plan.md ×1, <!-- @rename-keep -->
  tests/GlowModelTest.php ×7 (@see lines) → manual sed to plan_candy-mdview.md. Path TARGETS (pathContext <!-- @rename-keep -->
  relaxes `_`) ARE renamed by the tool: findings/plan + candy-mdview.md, docs/repo_map/{sugarcraft,update_sugarcraft}_*, <!-- @rename-keep -->
  docs/_data pair, docs/lib page, docs/research/libraries/*, media/docs icon pngs.
  No content refs to the `_sugar-glow` repo_map filenames exist (verified grep-empty). <!-- @rename-keep -->
  `_sugarglow`/`sugar_glow`/`_SUGARGLOW` underscore/glued variants: ZERO hits tree-wide (only the bin <!-- @rename-keep -->
  literal `bin/sugarglow` itself, which the bins map covers). <!-- @rename-keep -->
- MATCHUPS row already under `## Apps` @77 → R4-8 no section move. PROJECT_NAMES :84/:199 rewritten by tool.
- Generic-word "glow" landscape: zero hits inside themes/*.json + lang (no theme named glow); upstream
  charmbracelet/glow URLs/keywords everywhere → must SURVIVE untouched (rules never fire on bare `glow`).
  Audit executed in R6 (B4 method): corruption scan zero hits; survivors sampled — see R6.
- mdview collision: zero pre-existing `MdView`/`mdview` tokens outside plan/tools (verified pre-R3).
- Tool-internal fact (worth reuse): pascalCase('candy-mdview') = `CandyMdview` (ucfirst per segment,
  NOT CandyMdView) — same shape as B4 CandyScriptui. The `CandyMdview` class word appears in generated
  paths/prose; namespace root stays `SugarCraft\MdView`.
- oc.7z foreign 413MB → /tmp/oc.7z.b5-hold (same fs, instant mv); RESTORE at close.

## R1 dry-run
`php tools/rename-lib.php --old=sugar-glow --dry-run` (after tag-prep 40155b129 + notes skeleton 99bd7bebb): <!-- @rename-keep -->
121 files + 11 path renames; rule tally R1=54 R2=32 R3=336 R4=40 R5=6 R6=1. Sane: dir move, bins map,
ns pair Glow→MdView both spellings, update_sugarcraft path move via pathContext, NO tool-file/self-hits,
sibling-prefix protection inert (no sugar-glow* siblings). Report kept at /tmp/rename-sugar-glow.txt. <!-- @rename-keep -->

## R2 remote rename
`scripts/rename-remote.sh sugar-glow candy-mdview`: GitHub rename SUCCEEDED, then the script DIED on <!-- @rename-keep -->
topics push — HTTP 422: repo carried 20 topics incl stale brand `candycore` + noise `readme`; new keywords
(+viewer, +sugarcraft) would make 21. Cure: `unset GITHUB_TOKEN && gh repo edit sugarcraft/candy-mdview
--remove-topic candycore --remove-topic readme`, re-ran the script → idempotent convergence ("already
resolves … re-syncing"). Verified: description 'Markdown CLI viewer + pager — port of charmbracelet/glow.',
topics=20 exact, old `sugar-glow` repo redirects ✓. <!-- @rename-keep -->

## R3 tool run
`php tools/rename-lib.php --old=sugar-glow` rc=0: 121 files rewritten + **12** path renames (dry-run's 11 <!-- @rename-keep -->
+ the lib dir itself). sugar-glow/ gone → candy-mdview/. php -l sweep across changed EXISTING php: all clean <!-- @rename-keep -->
(gotcha: first sweep fed rename-SOURCE paths to php -l → false-reds; filter by file_exists). NAMESPACE='mdview'
written; Application ctor 'candy-mdview'; tapes ./bin/candy-mdview ✓. Zero hyphen-identifier breakage (no
camelCase collisions minted).

## R4 manual followups (all python-asserted single-purpose patches)
a. Brand class GlowModel→MdViewModel: git mv src/GlowModel.php→src/MdViewModel.php + tests
   GlowModelTest/GlowModelReloadTest→MdView* + fixtures glowmodel-{plain,styled}.golden→mdviewmodel-*; <!-- @rename-keep -->
   token replaced across 9 files (counts: MdViewModelTest 19, MdViewModelReloadTest 14, RenderCommand 6,
   GoldenRenderTest 6, ReloadTickMsg 2, WidthPlumbingTest 2, MdViewModel.php 1, CALIBER 1, RenderCommandTest 3)
   + `glowmodel-` fixture literals ×2 GoldenRenderTest + testGlowModelWith* method names. <!-- @rename-keep -->
b. Blindspot content refs (b-sweep above) — findings ×3, @see lines ×7.
c. Lang.php docblock 'glow'→'mdview' ×1.
d. ReloadTickMsg::ID 'glow.reload'→'mdview.reload' (internal tick id, test compares constant). <!-- @rename-keep -->
e. WidthPlumbingTest method+prose (testNoMdViewLocalWidthHelperIsResurrected, viewer's live render path);
   LangTest ns assert/method/synthetic-key.
f. CALIBER_LEARNINGS method-ref + glow-local prose follow rename; `pattern:glow` tags STAY (ruling above). <!-- @rename-keep -->
g. tempnam prefixes 'glow-*'→'mdview-*' (RenderCommandTest ×15, MdViewModelReloadTest ×1, GlamourThemeTest ×3). <!-- @rename-keep -->
h. examples/render-readme.sh: tool's CANDY_MDVIEW_BIN kept, but rewrite minted the INVALID bash function
   name `candy-mdview()` → manual run_mdview() (def + export -f + loop call).
i. README Formerly block under `# CandyMdview` H1 (2 tagged lines, Formerly/Namespace-changed per R4.1).
j. composer.json description de-framed per R4.2: 'Markdown CLI viewer / pager composing CandyShine +
   Viewport, modeled on charmbracelet/glow.' — keywords keep glow+markdown+sugarcraft (upstream keyword ✓).
Post-R4 sweep: zero untagged old vocab in the lib (vendor/ copies die at R5 relink).

## R5 wiring
- `composer update sugarcraft/candy-mdview --no-install` from ROOT: lock diff 67+/67− — sugarcraft block
  relocated `s`→`c` in sorted packages (+ content-hash dc0ed82c…→aade7745…); all 134 changed lines are the
  moved block (non-name lines = balanced relocation pairs). ✓ sugarcraft-only.
- gen-docs.php: 70 files (libraries 40, apps 21); --check ok 61 pages + 9 stubs (sugar-glow stub mints on <!-- @rename-keep -->
  R7 status flip — redirect_stubs() skips status=pending; re-run inside R7 → expect 10).
- gen-ansi-readme --check: agree across 3595 pieces (ansi.json untouched — tiles are C.3 art content).
- refresh-deps --mode=linked --libs=candy-mdview: first run flagged escaping=1 — STALE pre-rename dangling
  symlink vendor/sugarcraft/candy-zone (candy-zone was batch-1 merged→candy-mouse; dir gone since long before
  this lane; sugar-glow's vendor predated it). Removed the dead link, re-ran: 16/16 symlinked, escaping=0, ok; <!-- @rename-keep -->
  committed-tree guard --no-lib-path-repos rc=0. Not rename debt — inherited vendor cruft, disclosed.
- Consumers grep: zero `sugarcraft/sugar-glow` in any */composer.json ✓ (tool rewrote root :88). <!-- @rename-keep -->

## R6 verification
(pending — gates run at stage point)

## R7 commit
(pending)

## R8 push + remote-done
(pending)

## R9 packagist
(pending)

## Tracker
(pending)
