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
- JUDGED-REWRITE (living, tool handles): README.md AGENTS.md CLAUDE.md CONTRIBUTING.md SECURITY.md .claude/ .cursor/ docs/** (incl repo_map living set + research + _data + MATCHUPS + index/404/search-index/sitemap), codecov.yml ci.yml vhs.yml .gitattributes scripts/** tools/check-path-repos.php (example docblock — verify harmless), candy-code src+tests prose (DiffGutter/App/Bootstrap/SettingsPane/InstructionFileLoader docblocks, examples/agent-dashboard.php fixture text), candy-mdview/scriptui/core/bits/widgets prose+composer descriptions, findings/{plan,README,candy-mdview,repeated_logic,improvements→judged per D1: improvements-tag-family}, docs/plans/{plans_cleaning,UPSTREAM_OPPORTUNITIES}.md, docs/old/** EXCLUDED by C.3 (no tags).

## Self-lock law

NEVER tag (paths must move): candy-shine/**, findings/{candy-shine.md,plan_candy-shine.md}, docs/_data/candy-shine.{json,body.html}, docs/lib/candy-shine.html, docs/repo_map/{sugarcraft_candy-shine.md,update_sugarcraft_candy-shine.md}, media/icons. README Formerly notice added POST-move to sugar-markdown/README.md. <!-- @rename-keep -->

## Steps log

- (in flight) R0 baselines → tag-prep commit → dry-run → tool run → manual sweeps → flip+gen-docs+lock → rename commit → gates on committed tip → NOTES commit → push branch.

## Steps log
- R0 base @55fb3bb93: shine 938T/2378A rc0 = catalog; mdview 122T/313A; scriptui 334T/694A; crush --filter Markdown 42T/134A; crush --filter Shine VACUOUS ("No tests executed") at base — live-prose test names never carried the brand; recorded for tip comparison.
- tag-prep commit: 105 sentinels (docs ledgers 50, InstructionFileLoaderTest 43 fixture lines, RuntimeTest 2, 4×2 defect-narrative + 1×1 measurement-trail docblock lines, NOTES self).
- R1 tool: 277 files, 9 paths (+lib dir), R1=218 R2=133 R3=526 R6=1 (Lang NAMESPACE flip). Renamed: lib dir, docs/_data pair, icon pair, docs/lib page, repo_map pair, findings pair.
- MANUAL sweeps landed: (1) Theme.php `as ShineTheme` alias → `as MarkdownTheme` 10 occurrences (R2 lookahead `(?![a-z0-9])` matches `ShineTheme`?? NO — `Theme` after is [A-Z] → hmm: R2 pattern requires preceding boundary; `as ShineTheme` — Shine followed by T passes `(?![a-z0-9])`… tool still left it: R2 applies to pascal `CandyShine` only, bare `Shine` identifier = R5 class (UPPER-only) → alias invisible; D1 law confirmed). (2) mdview public API toShineTheme→toMarkdownTheme 14 sites + testMarkdownTheme* 4 + RouteToMarkdown 1 (assert-count 6 failed first → 10 call sites, corrected). (3) RendererTest 2 prose. (4) CAP-HYPHEN `Candy-Shine` ×2 (widgets CALIBER live pattern, own repo_map feature-table header — NEW: uppercase-hyphen brand survived C-lanes too). (5) `sugar-shine` typo ×7 across 5 repo_map files (incl. two `sugar-markdown / sugar-shine` pairs collapsed after tool rewrote one half — dedupe needed judgement). (6) glued `_`-blocked dead pointers: plan.md 1, findings_resume_plan.md 3 (awk exit-1-per-file initially undercounted to 1), candy-mail repo_map 1. (7) resume_prompt.md:51 TAGGED (historical ✅ line). (8) candy-git/src/DiffHighlighter.php `Shine\SyntaxHighlighter`→`Markdown\SyntaxHighlighter` — candy-git is a PROSE consumer not in any manifest census (NEW blindspot class: composer-less FQN-in-comment consumers). <!-- @rename-keep lane record quoting old token -->
- ReadOnlySedAndLoopsTest ns-roster heredocs (`Shine` inside recorded-ask transcripts): LEAVE — inert historical command transcripts, already carry stale `Veil` post-C4, asserts don't require non-empty.
- README Formerly 2-line notice (veil precedent) + renames.json:35 flipped pending→renamed via targeted sed (file is dict{$comment,entries}).
- gen-docs minted 31st redirect stub docs/lib/candy-shine.html (self-tagged, residue-invisible ✓); root composer.lock updated (--no-install); refresh-deps 4/4 linked escaping=0 after purging 3 stale BROKEN candy-shine vendor symlinks my own R0 link left behind (gitignored cruft; the escaping=1 "WANTED LINKED" scare = verify() counts realpath(false) as escaping — stale-symlink fingerprint, NEW harness lesson). <!-- @rename-keep lane record quoting old token -->

## Gate evidence (tip 588d2cb25, all on committed tree)
- residue --old=candy-shine: 0 findings rc0 (after 2 self-tags on NOTES step-log lines — lane records quoting old tokens need sentinels too) <!-- @rename-keep -->
- sugar-markdown suite: OK 938T/2378A rc0 = base EXACT (LangTest namespace-literal failure caught + fixed in sweeps commit)
- candy-mdview 122/313 OK; candy-scriptui 334/694 OK (both = base EXACT)
- candy-code --filter Markdown 42/134 OK = base; --filter Shine "No tests executed" = base shape (vacuity documented)
- tools/tests 426/1484 OK (RenameLibTest fixture coupling unaffected by status flip — D1 proof reconfirmed); check-path-repos rc0; check-child-lifetimes rc0; gen-docs --check rc0 (31 stubs incl candy-shine.html); gen-ansi-readme --check rc0 <!-- @rename-keep -->
- candy-git: docblock-comment-only edit, php -l clean, suite not baselined at R0 (disclosed)
- NEW BLINDSPOT CLASSES this lane: (1) bare lowercase ns-word (`shine`, incl `'shine.'` Lang key prefixes + tempnam prefixes) invisible to every rule incl residue; (2) leadcap-hyphen `Candy-shine` variant survived; (3) U+2011 non-breaking-hyphen `candy‑shine` in php74_branch.md invisible (tagged); (4) prose-only consumer libs absent from composer/NS censuses (candy-git); (5) stale broken vendor symlink after dir rename surfaces as refresh-deps escaping=1 / "*** WANTED LINKED ***" red herring — purge find -type l -delete; (6) `$<var>`-style vars named from brand ($shine locals) judged cosmetic-keep (identifier, not token).
