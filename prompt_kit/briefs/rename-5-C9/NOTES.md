# rename-5-C9 — sugar-bits → sugar-widgets (Procedure R, isolated branch) <!-- @rename-keep -->

State file, updated incrementally per step. History lines mentioning old vocabulary carry `@rename-keep` as written. <!-- @rename-keep -->

## Entry facts (verified)

- tools/renames.json line 29: kind=rename, sugar-bits → sugar-widgets, type=lib, ns Bits→Widgets, bins {} (no bins). <!-- @rename-keep -->
- Derived (RenameLib::derive): R1 ns `SugarCraft\Bits`→`SugarCraft\Widgets` (SCOPED — bare word "bits"/"Bits" untouched by any rule); R2 pascal `SugarBits`→`SugarWidgets` (59 sites tree-wide incl. crush composer description); R3 slug; R4 compact `sugarbits`→`sugar-widgets` (only occurrence = ansi art bytes, content-excluded); R5 env prefixes `SUGAR_BITS_`/`SUGARBITS_` → ZERO reads tree-wide (honest no-LegacyEnv); R6 Lang domain `bits`→`widgets` (src/Lang.php:20; check test pins). <!-- @rename-keep -->
- PROTECT auto: none (no `sugar-bits-*` entries). New-name collision: `Widgets`/`sugar-widgets`/`SugarWidgets` pre-existence → dry-run preflight gates ns collisions; `ls -d *widget*` check below. <!-- @rename-keep -->
- R0 baseline (measured at branch base 4d472bc62, linked, plain pipe, timeout 600): sugar-bits **570T/1246A/0F/0E/0S/0W** EXACT vs rename-baseline.md row. <!-- @rename-keep -->
- Direct composer consumers: candy-mdview, candy-sql, candy-top (@dev), sugar-stickers (@dev) + root (require+repositories). NO PHP `SugarCraft\Bits\` consumer outside lib beyond: candy-mdview 1 file, candy-sql 3, candy-top 5, sugar-stickers 4 (grep census). candy-forms mention = composer DESCRIPTION prose ("extracted from sugar-bits") only. sugar-crush = text-level consumer: composer description ×2 "SugarBits", 2 src docblocks, 1 test fixture mkdir `sugar-bits/src` (InstructionFileLoaderTest) — NO composer require. candy-ssh-launcher SugarBits ×2 = prose only (dep pruned at W7 #1305 era... actually AND_REPO pruned; comment survives) → R2 rewrites prose, no code coupling. <!-- @rename-keep lane work-record, as written -->
- Facade law: sugar-bits aliases `SugarCraft\Forms\*` (candy-forms canonical, DIFFERENT lane C10) — alias tests ride the rename (they live under sugar-bits/tests); candy-forms/ dir untouched beyond zero edits (its composer has NO sugar-bits require — description prose auto-rewritten by R3 only, which is a candy-forms/composer.json line C10 may also touch → weld resolves). <!-- @rename-keep lane work-record, as written -->
- ansi/: NO ansi/sugar-bits/ dir; wordmark `SUGARBITS · COMPONENT RACK` lives in ansi/sugarcraft/sugarcraft-2-16-m-2.ansi CONTENT (excluded) → followups entry. <!-- @rename-keep lane work-record, as written -->
- media/icons/sugar-bits.png, docs/_data pair, docs/lib page, findings pair (plan_sugar-bits.md — tool renamed it for sugar-veil at same weld base per C4; verify in dry-run path list), repo_map pair + update pair → tool path rules. <!-- @rename-keep lane work-record, as written -->
- icon-sync parity gate: docs/img/icons parity via generate_icons.py dict key R3-rewrites, PNG path renames (binary content-safe).

## Tag-prep (historical provenance → @rename-keep)

Files judged HISTORY (tag in-place): prompt_kit/CONTEXT.md ×13 untagged lines, prompt_kit/briefs/rename-baseline.md:67 row, docs/plans/crush_code_{worklog×7,hardening_backlog×9,RESUME×2,backlog_triage×3}, findings/improvements-2026-05.md ×12, findings/monorepo-audit-2026-05-27.md ×9, findings/resume_prompt.md ×4, php74_branch.md ×2, docs/plans/leftover/updates.md ×8, prompt_kit/briefs/{candy-top-panel-phase-common:27, candy-top-pf-common:16, rename-1.1-zone-merge:45,56, rename-2.4/NOTES:50, rename-4-B4/NOTES:16, rename-4-B6/NOTES:24}.
Judged LIVING (rewritten by design): docs site + repo_map/research bodies, .claude/.cursor/.sugar-crush skills, READMEs (root + lib + consumers incl. candy-forms CALIBER/README description prose), findings living docs (sugar-bits.md pair renames, cross-lib findings), codecov/vhs.yml/ci.yml/scripts, PROJECT_NAMES/AGENTS/CLAUDE/MATCHUPS, plan_crush_media.md, leftover step files, docs/plans/leftover READMEs. <!-- @rename-keep lane work-record, as written -->

## Steps

- (done) worktree `/home/sites/sugarcraft-c9` branch `rename/c9` @ weld `4d472bc62`; refresh-deps --mode=linked sugar-bits,candy-mdview,candy-sql,candy-top,sugar-stickers = linked=5 rc=0. <!-- @rename-keep lane work-record, as written -->
- tag-prep: @commit 78+6=84 lines / 17 files (CONTEXT×13, baseline:67, worklog×7, hardening×9, RESUME×2, triage×3, improvements-2026-05×12, monorepo-audit×9, resume_prompt×4, php74×2, leftover/updates×8, C-briefs/rename-briefs×7, own NOTES×6). <!-- @rename-keep lane record, as written -->
- R1 dry-run: 451 files / 10 paths / R1=374 R2=58 R3=1213 R6=1 (R4=0 R5=0 — compact only in ansi art bytes [content-excluded]; zero env sites tree-wide). findings/plan_sugar-bits.md AUTO path-renamed (C4 finding confirmed at this base). Glued `_sugar-bits` blindspot hits: repo_map self-pair pointers ×9, plan_sugar-bits.md cites ×8 (incl. candy-core CALIBER:56 tail), Sugar-bits cap-hyphen ×3, `.vhs/filter.tape` SugarBits escaped-\\n blindspot ×1 (NEW: R2 lookbehind defeated by literal backslash-n in tape content). /tmp/opencode/c9-dryrun.txt + -v. <!-- @rename-keep lane record, as written -->
- R2 `rename-remote.sh sugar-bits sugar-widgets`: repo renamed + description/homepage synced (rc=0 path); **topics 422 >20 GitHub cap — topics sync declined by API, old topic set stays (owner curation, B12 #5 precedent). Deviation recorded.** Verified: sugarcraft/sugar-bits resolves to sugar-widgets. <!-- @rename-keep lane record, as written -->
- R3 tool: rc=0 EXACT counts vs dry-run; dir moved; internal php -l/composer validate/one-type-per-file pass.
- R4 manual: LangTest 'bits'→'widgets' ×2 (R6 domain const + raw-key passthrough expectation 'widgets.nonexistent.key'); src/Lang.php docblock; formerly+namespace notices in sugar-widgets/README.md; PROJECT_NAMES:173 strikethrough-resolution line tagged; glued sweeps 21 replacements/14 files as listed. <!-- @rename-keep lane record, as written -->
- R5: root composer update sugarcraft/sugar-widgets --no-install → lock diff EXACTLY one package entry (86/86 all inside sugar-bits→sugar-widgets). gen-docs 78 files, --check ok (61+17 stubs; sugar-bits redirect stub NOT yet minted — status=pending; expect mint after R7 re-run). gen-ansi --check ok (3595). refresh-deps linked=5 escaping=0 after pruning 4 stale dangling vendor/sugarcraft/sugar-bits symlinks (composer update did not prune dangling path links — law for future lanes). <!-- @rename-keep lane record, as written -->
- R1 dry-run:
- R2 remote rename:
- R3 tool run:
- R4 manual:
- R5 wiring:
- R6 gates:
- R7 commit:
