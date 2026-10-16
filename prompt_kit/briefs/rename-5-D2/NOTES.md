# rename-5-D2 NOTES — candy-shine → sugar-markdown <!-- @rename-keep -->

Lane: D2 (batch-D line 35, ns Shine→Markdown, type=lib, bins {}). Branch `rename/d2` off origin/master `55fb3bb93`, worktree `/home/sites/sugarcraft-d2`. NEVER push master; push branch only. No R8/R9, NO remote rename, NO packagist (serial post-weld phase owns remotes — D1 law). <!-- @rename-keep -->

## Entry facts (verified on disk)

- tools/renames.json line 35: kind=rename, candy-shine → **sugar-markdown**, lib, ns Shine→Markdown, bins {}, no extra_literals/protect, status pending. Derived: pascal CandyShine→SugarMarkdown, compact candyshine (ZERO sites tree-wide), env CANDY_SHINE_/CANDYSHINE_ (ZERO sites → honest none, no LegacyEnv). <!-- @rename-keep -->
- R6 Lang domain: candy-shine/src/Lang.php NAMESPACE='shine' → tool flips to 'markdown'. 16 locale files, bare keys. <!-- @rename-keep -->
- Pre-existence census: `SugarCraft\Markdown` — only plan_rename.md + RenameLibTest fixtures (both rule-excluded); `SugarMarkdown` dir/class/repo 0 — clean. <!-- @rename-keep -->
- **Fixture coupling (unique to this lane):** tools/tests/RenameLibTest.php drives the tool with real-map entry candy-shine→sugar-markdown as its PERMANENT rule-differential fixture, and rename-lib.php EXCLUDED_EXACT contains BOTH tools/renames.json and tools/tests/RenameLibTest.php (:81/:84) → zero tag burden there, ledger old-field stays, test proven green with flipped status by D1 (tools/tests 426T at tip). <!-- @rename-keep -->
- Class-name precedent (C4 law): NO class Shine exists (src has Renderer/Theme/Writer/StyleGuide/SyntaxHighlighter/GithubEmoji/Lang + Render/ Style/) — nothing to keep; docs/old "Renderer as Markdown" cite excluded. sugar-crush `use SugarCraft\Shine\Renderer as Markdown` stays (R1 → `...Markdown\Renderer as Markdown`, legal PHP, no collision). <!-- @rename-keep -->
- Consumer manifests requiring sugarcraft/candy-shine: candy-mdview, candy-scriptui, sugar-crush (+root). NS-ref libs: same three + tools (RenameLibTest, excluded). <!-- @rename-keep -->
- Baselines: rename-baseline.md:56 shine 938T/2378A/0/0/0/0 (RE-MEASURE at base — worklog :14264 shows era 573/1,075 predating y4 stream work; :14279 weld-era). candy-mdview + candy-scriptui ABSENT from baseline table (DISCLOSED: measure at base for comparison, C8 precedent). crush pinned 22564/423652/2S. <!-- @rename-keep -->
- Wiring: codecov.yml name/paths/component (:68/:69/:286-291), vhs.yml all= :146 + matrix :225, ci.yml comment :547, scripts/affected-libs.php :31 roster, .gitattributes :4, use_libs.txt :20, scripts/generate_icons.py + TRACKED __pycache__/generate_icons.cpython-313.pyc (binary, NUL-excluded content — verify residue skip), issue templates, CONTRIBUTING/SECURITY/README/AGENTS/CLAUDE + .claude/.cursor skill mirrors — all auto R2/R3. media/icons/candy-shine.png (C.2 path). NO ansi/candy-shine/ dir (wordmark-free). NO shine research file. .vhs tapes: shine's own carry no slug; candy-scriptui/format.tape :7 `*CandyShine*` is R2-visible (pre=`*`) → auto. <!-- @rename-keep -->

## Manual blindspot set (R4-lane, post-tool)

1. sugar-crush/src/Theme.php `use SugarCraft\Shine\Theme as ShineTheme` — CamelCase-joined import alias (D1 `as MosaicHalfBlockRenderer` precedent) → `as MarkdownTheme` + 9 sites (docblock :20/:40, property :123, calls :179×2/:213/:214, param :237, comment :266). <!-- @rename-keep -->
2. candy-mdview public API `toShineTheme()` → `toMarkdownTheme()` (GlamourTheme.php:185 + docblock :27; RenderCommand.php:199 docblock + :218 call; GlamourThemeTest 13 sites incl. test method names testShineTheme* + RouteToShinePrefixSuffix + prose "Shine prefix"). Local `$shine` vars KEPT (not rule tokens, not API). <!-- @rename-keep -->
3. WidthPlumbingTest.php:21 prose "the Shine word-wrap" → "the sugar-markdown word-wrap". <!-- @rename-keep -->
4. TYPO VARIANT `sugar-shine` (R-invisible): docs/repo_map/{charmbracelet_gum ×3, charmbracelet_pop ×4, pr_charmbracelet_gum ×2, pr_charmbracelet_pop, pr_ratatui_ratatui} → rewrite to sugar-markdown; bare "`Shine` is the styling utility lib" pop:117 → rewrite. (D1 rewrote `sugar-mosaic` typo variant.) <!-- @rename-keep -->
5. Glued pointers `sugarcraft_candy-shine.md` content refs (R-invisible `_` lookbehind): docs/repo_map/sugarcraft_candy-mail.md:742 → rewrite (target exists post-move). `plan_candy-shine.md` content refs: findings/findings_resume_plan.md :772/:784/:1292 + findings/plan.md:191 → REWRITE living (targets exist post-move); findings/resume_prompt.md:51 → TAG historical (D1 tagged resume_prompt family). <!-- @rename-keep -->
6. prompt_kit/tools/crush-dep-surface.php:20 map VALUE 'Shine'→'Markdown' (key auto-R3). <!-- @rename-keep -->
7. `docs/candy-shine_scores.md` — 3-A1 NOTES:51 documents it as the must-NOT-move negative fixture (segment blocked by `_` in R3 path rule); verify it does not exist tracked, stays alone either way. <!-- @rename-keep -->

## Tag-prep set (history → @rename-keep, per-line, rule-token-bearing only)

- prompt_kit/CONTEXT.md :940 :946 (13 already tagged).
- docs/plans/crush_code_hardening_backlog.md 14 untagged, worklog 20, RESUME :101, filemap, triage 2, concurrency 2. <!-- @rename-keep -->
- php74_branch.md ×2. prompt_kit/briefs/rename-baseline.md:56. rename-2.2/3-A1:51/4-B5:15+89+109/4-B7:19+30/4-B12:29 NOTES lines. prompt_kit/findings/crush-dep-surface.txt:65. <!-- @rename-keep -->
- sugar-crush/tests/Context/InstructionFileLoaderTest.php touch-fixture line (`/candy-shine/CALIBER_LEARNINGS.md`) — FUNCTIONAL synthetic-tree fixture, glob `candy-*/` coupled: rewrite would break assertCount(2). TAG with functional-keep rationale (new blindspot: fixture path strings inside LIVE tests are not necessarily historical — judge by mechanism, not file age). <!-- @rename-keep -->
- JUDGED-REWRITE (living, tool handles): README.md AGENTS.md CLAUDE.md CONTRIBUTING.md SECURITY.md .claude/ .cursor/ docs/** (incl repo_map living set + research + _data + MATCHUPS + index/404/search-index/sitemap), codecov.yml ci.yml vhs.yml .gitattributes scripts/** tools/check-path-repos.php (example docblock — verify harmless), sugar-crush src+tests prose (DiffGutter/App/Bootstrap/SettingsPane/InstructionFileLoader docblocks, examples/agent-dashboard.php fixture text), candy-mdview/scriptui/core/bits/widgets prose+composer descriptions, findings/{plan,README,candy-mdview,repeated_logic,improvements→judged per D1: improvements-tag-family}, docs/plans/{plans_cleaning,UPSTREAM_OPPORTUNITIES}.md, docs/old/** EXCLUDED by C.3 (no tags).

## Self-lock law

NEVER tag (paths must move): candy-shine/**, findings/{candy-shine.md,plan_candy-shine.md}, docs/_data/candy-shine.{json,body.html}, docs/lib/candy-shine.html, docs/repo_map/{sugarcraft_candy-shine.md,update_sugarcraft_candy-shine.md}, media/icons. README Formerly notice added POST-move to sugar-markdown/README.md. <!-- @rename-keep -->

## Steps log

- (in flight) R0 baselines → tag-prep commit → dry-run → tool run → manual sweeps → flip+gen-docs+lock → rename commit → gates on committed tip → NOTES commit → push branch.
