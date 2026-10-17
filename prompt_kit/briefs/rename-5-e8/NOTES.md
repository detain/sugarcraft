# rename-5-e8 NOTES — candy-buffer → sugar-cellbuffer <!-- @rename-keep -->

Lane: e8 (batch-E line 45, ns Buffer→CellBuffer — NS WORD CHANGES, type=lib, bins {}). Branch `rename/e8` off `6709f44bd`, worktree `/home/sites/sugarcraft-e8`. NEVER push master; push branch only. No R2 remote / no R8/R9 (brief law — serial agent post-weld owns remotes/packagist). <!-- @rename-keep -->

## Entry facts (verified)

- tools/renames.json :45: kind=rename, old candy-buffer → **sugar-cellbuffer**, lib, ns Buffer→CellBuffer, bins {}, no extra_literals/protect, status pending → flip in rename commit (targeted sed, own line only). <!-- @rename-keep -->
- Derived: pascal CandyBuffer→SugarCellbuffer (R2 — README/MATCHUPS/PROJECT_NAMES/_data brand prose only), compact candybuffer 0 sites, env CANDY_BUFFER_/CANDYBUFFER_ **0 sites → honest none, no LegacyEnv**. R6: src/Lang.php NAMESPACE='buffer' → 'cellbuffer'. <!-- @rename-keep -->
- PROTECT check: no `candy-buffer-*` sibling; no sugar-* entry prefixed candy-buffer- → auto-protect empty, no brand_protect lever needed (unlike e6/ansi-inspect). <!-- @rename-keep -->
- R1 pre-existence: `SugarCraft\CellBuffer`/`sugar-cellbuffer` in plan_rename.md, renames.json, docs/repo_map/{sugarcraft_candy-core,ECOSYSTEM_INTELLIGENCE_SUMMARY}.md (target-name-forward docs, NOT src/tests/composer) → preflight clean. <!-- @rename-keep -->
- Class-name law (C4/D1 precedent): `src/Buffer.php` class **Buffer** + Cell/Position/Region/Style/Hyperlink keep their class names under `SugarCraft\CellBuffer\`; bare-Buffer symbols (method `toBuffer`, `BufferHelper`, `RingBuffer`, own-name tests) KEPT — generic word, never swept. Consumer aliases `as BufferRegion/BufferStyle/BufferPosition` KEPT (refer to surviving class Buffer, no brand word, unlike D1's MosaicHalfBlock which carried the CandyMosaic brand). <!-- @rename-keep -->
- candy-vt's own `SugarCraft\Vt\Buffer` + sugar-dashboard's `Foundation\Buffer` + charts `Charts\Buffer\BufferHelper`: R1 pattern requires `SugarCraft\` immediately before `Buffer` → structurally unreachable. Verified 0 vt/dashboard Buffer-class renames planned. <!-- @rename-keep -->
- Consumers (15 require + 2 ns-only): require = candy-testing, candy-minesweeper, sugar-charts, sugar-forms, sugar-treelist, sugar-table, sugar-video, sugar-panels, candy-tetris, sugar-overlay, sugar-dashboard, sugar-datepicker, candy-sprinkles, sugar-toast, root. ns-code-only via require-dev transitivity: sugar-flexbox (TableRenderer uses Buffer/Cell/DiffEncoder; composer require lacks candy-buffer — CHECK gate will flag unused? no: check-path-repos validates declared-closure only) + candy-layout (docblock-only `{@see \SugarCraft\Buffer\Region}`, no require). sugar-crush = 5 docblock/comment path refs only (R3), no require. <!-- @rename-keep -->
- Wiring: codecov.yml (R3 auto), ci.yml + sync-sugarcraft.yml rows (R3), scripts/refresh-deps.php:57 + tools/check-path-repos.php:255 comments (living, rewrite), steps/J1_COMPOSER_REPOS.md, docs/index.html. NO .vhs dir (non-visual exempt), NO ansi/candy-buffer art dir (but 6 foreign-lib .ansi wordmark/prose hits — content excluded by `$extension==='ansi'` law → followups), NO child-lifetimes/pty-matrix/affected-libs rows. Paths C.2: lib dir, findings/{candy-buffer,plan_candy-buffer}.md, docs/_data/candy-buffer.{json,body.html}, docs/lib/candy-buffer.html, media/icons + docs/img/icons pngs. <!-- @rename-keep -->
- Baselines RE-MEASURED at base 6709f44bd (linked vendor, parallel plain-pipe): own candy-buffer **315T/1614A OK** (catalog 315/1712 — test count EXACT, A-count rand() noise per CONTEXT:1149 law, disclose). consumers: layout 293/3315, minesweeper 180/820/**1F/4W standing**, sprinkles 865/2896, testing 267/5416/3S (catalog 267/5417/2S drift disclosed), tetris 214/4322, charts 654/1551, dashboard 7483/14859/**2F standing**, datepicker 149/382, flexbox 221/476, forms 2343/4564, overlay 258/561, panels 201/456, table 510/1237, toast 278/669, treelist 162/386, video 552/8488/1S. crush --filter 'Buffer|CellGrid' **53T/247A OK**; tools/tests **426T/1484A** (pre-e6-patch figure, matches brief expectation for 6709f44bd base). <!-- @rename-keep -->

## Self-lock law

NEVER tag any line inside candy-buffer/** (self-lock sentinel skips whole path plan). NEVER tag: findings/candy-buffer.md, findings/plan_candy-buffer.md, docs/_data/candy-buffer.{json,body.html}, docs/lib/candy-buffer.html, icons (binary), this NOTES' token lines are tagged but the file path carries `candy-buffer` only in the header → header carries sentinel already (e1 precedent). README Formerly+Namespace blockquotes added POST-move to sugar-cellbuffer/README.md. <!-- @rename-keep -->

## Tag-prep set (history → @rename-keep, per-line)

- prompt_kit/CONTEXT.md :917 :922 :1149 :1151 (:957/:987/:1009/:1012/:1013/:1022 already tagged by prior lanes)
- prompt_kit/briefs/rename-baseline.md :32 (row, sentinel inside trailing cell)
- docs/plans/crush_code_hardening_backlog.md :10894 :15167 :15170; crush_code_worklog.md :170 :6270 :10562 :10564 :10638 :10641
- php74_branch.md :350 :355 :501 (:540 already tagged)
- findings/resume_prompt.md :99 (:16/:98 already tagged)
- prompt_kit/briefs/rename-5-C6/NOTES.md :17 — ALREADY tagged, verified.
- JUDGED-REWRITE (living, tool handles): all 15 consumer composers+code+READMEs/CALIBERs, docs/repo_map_step_*.md + supervisor/update/role files, docs/repo_map/sugarcraft_sugar-overlay.md, findings/{README,candy-testing,findings_resume_plan,plan,plan_candy-vt,plan_repeated_logic,plan_sugar-cli,plan_sugar-dashboard,repeated_logic,sugar-cli,sugar-dashboard,sugar-forms}.md, docs/plans/plans_cleaning.md, steps/J1, PROJECT_NAMES/MATCHUPS/root README/AGENTS/CLAUDE/CONTRIBUTING/.claude SKILL, codecov.yml, ci.yml, sync-sugarcraft.yml, scripts/refresh-deps.php comment, tools/check-path-repos.php comment, sugar-crush ×5 comment/docblock path refs.
- C.3-excluded (no tags): docs/old/**, */vendor/**, composer.lock (stale candy-buffer dist until WELD lock refresh — 01c87e172 precedent), *.ansi content, plan_rename.md/renames.json/rename-lib.php/check-rename-residue.php/tools tests (exact-path excludes). <!-- @rename-keep -->

## Steps log

- R0: done — figures above.
- tag-prep: (pending hash) 18 lines / 6 files + this NOTES.
- R1 dry-run / R7 tool run: (pending).
- R4 manual sweeps: (pending — glued plan_candy-buffer.md ×7 incl own CALIBER_LEARNINGS:8; LangTest 'buffer.x.y'→'cellbuffer.x.y' per candy-mail precedent; lang/en.php comment key; Formerly+ns blockquotes; U+2011/underscore/compact/Cap-Hyphen sweeps). <!-- @rename-keep -->
- R5 wiring: renames.json flip → gen-docs (redirect stub docs/lib/candy-buffer.html self-tagged) → prune dangling */vendor/sugarcraft/candy-buffer symlinks → refresh-deps --mode=linked own+consumers+crush. <!-- @rename-keep -->
- R6 gates on committed tip: residue --old=candy-buffer 0; own EXACT vs R0; 17 consumers EXACT vs base (standing minesweeper 1F/4W + dashboard 2F never chased); crush filter 53/247; tools/tests 426/1484; path-repos/child-lifetimes/gen-docs --check/gen-ansi --check rc0; php -l sweep. <!-- @rename-keep -->
