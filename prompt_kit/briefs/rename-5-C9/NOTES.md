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
- tag-prep: (in progress)
- R1 dry-run:
- R2 remote rename:
- R3 tool run:
- R4 manual:
- R5 wiring:
- R6 gates:
- R7 commit:
