# Rename C10: candy-forms → sugar-forms (lib, ns Forms→Forms UNCHANGED) <!-- @rename-keep -->

<!-- @rename-keep: this file is the lane work-record; old-name mentions below are AS WRITTEN history. -->

## Verdict
(IN PROGRESS)

## Entry facts (verified)

- tools/renames.json line 30: kind=rename, candy-forms → sugar-forms, lib, ns_old=ns_new=Forms, bins {}, no extra_literals, no protect candidates. <!-- @rename-keep -->
- Derived: pascal CandyForms→SugarForms (R2), compact candyforms→sugar-forms (R4), env CANDY_FORMS_* ZERO sites tree-wide (honest none, B12 precedent), R6 NAMESPACE='forms' byte-stable (same ns). <!-- @rename-keep -->
- SAME-NS IS THE LANE SHAPE: SugarCraft\Forms namespace text is NEVER rewritten (R1 rule is generated only when ns_old≠ns_new, tools/rename-lib.php:529; the target-namespace collision scan is likewise guarded at :844 and the docblock :228 comment names the SugarCraft\Forms fold as the motivating case). No --no-move needed — <old>/ exists, <new>/ does not. <!-- @rename-keep -->
- Baseline rows in rename-baseline.md: :37 candy-forms 2326T/4529A (tagged this lane) and :79 the fold-era mislabel (sugar-prompt façade figures 72/158 rewritten to the candy-forms label by the step-1.2 fold run — tagged as written; leave). <!-- @rename-keep -->
- Live gate truth = pre-R3 measurement in THIS worktree (era 2343T/4564A per brief), not the baseline file. <!-- @rename-keep -->

## Hazard rulings (decision log)

- H1 SAME-NS TOOL BEHAVIOR: dry-run must show R1=0 (rule absent), zero `SugarCraft\Forms`→anything content changes; the plan's STOP condition ("0 replacements while ns_old ≠ ns_new") is inapplicable — R3 slug counts carry the run. R1 dry-run verdict recorded below. <!-- @rename-keep -->
- H2 SUGAR-PROMPT FOLD PROSE: candy-forms README/comments narrate the pre-rename fold ("former standalone prompt façade folded in") — sugar-prompt is NOT this lane's old token; nothing to tag, tool cannot touch it. The fold entry (renames.json:61 `"new":"candy-forms"`) gets its `new` bumped to sugar-forms in the R7 commit per plan Phase 5 bullet ("update the fold entry's `new` ... in this commit") so the gen-docs redirect chain + sync prefixes_for resolve through. <!-- @rename-keep -->
- H3 CONSUMERS: composer requires `sugarcraft/candy-forms` in candy-forms(self), candy-scriptui, candy-sql, sugar-bits, sugar-crush, sugar-readline (+ root composer.json require+repositories). C9 (sugar-bits) runs CONCURRENTLY: this lane edits ONLY candy-forms require lines inside sugar-bits files (never sugar-bits/sugar-widgets name tokens); expect weld-time merge on sugar-bits/composer.json + prose. Transitive mention prose in candy-core/candy-fuzzy/candy-layout/candy-mouse CALIBER/READMEs, .claude skills, AGENTS/CLAUDE, ISSUE_TEMPLATEs, ci/vhs/sync workflows, scripts (affected-libs, bootstrap-org-repos, set-org-default-master, generate_icons), tools (check-path-repos, gen-docs), codecov, use_libs — all live pointers, tool rewrites. <!-- @rename-keep -->
- H4 ENV/DATA: CANDY_FORMS_* zero; forms lib has no user-data paths (library, not app) — R4.4 honest none. No LegacyEnv/LegacyPaths needed. <!-- @rename-keep -->
- H5 ANSI CORPUS: ansi/candy-forms/ (~30 files) paths renamed by C.2 (tool, incl. the ansi-prefix rule); ansi/ansi.json keys/paths rewritten; art CONTENT C.3-excluded (wordmarks CandyForms/CANDY FORMS inside art survive → followups entry). ansi.json desc lines 2552 "Truecolor CandyForms wordmark..." (R2-rewritten → SugarForms, art-vs-description drift recorded in followups), 2768 "CANDYFORMS" all-caps (matches NO rule — stays honest by construction), "CANDY FORMS" space-form (untouchable). `ansi/sugar-crush/50-candy-forms.ansi` — path R3 boundary matches inside segment (preceded `-`, followed `.`) → expect rename to 50-sugar-forms.ansi; if the tool misses it, git mv by hand (content byte-untouched) + ansi.json ref rides R3. `ansi/sugarcraft/sugarcraft-{2-16-l-1,2-256-l-1,3-16-l-2,5-256-l-1}.ansi` banner art embeds the old slug in CONTENT → C.3-excluded, followups. media/icons/candy-forms.png + docs/img/icons/candy-forms.png path-only renames (binary). ansi/README.md is GENERATED — regenerate via gen-ansi-readme.php after tool, then --check. <!-- @rename-keep -->
- H6 gen-docs.php:315 redirect-stubs comment "resolve to candy-mouse and candy-forms (whose own renames are pending)" — after this rename the forms half is FALSE; R4 manual prose fix (drop the forms example, candy-mouse stays pending). <!-- @rename-keep -->
- H7 NO new SugarForms/sugar-forms collisions pre-run: hypothetical `sugar-forms` mentions exist only in docs/repo_map/charmbracelet_huh.md (+pr_) "(hypothetical)" future-name notes and rename-2.3 NOTES — harmless post-rename (they become TRUE). No class named SugarForms anywhere. <!-- @rename-keep -->
- H8 compact candyforms: exactly ONE site — candy-forms/tests/Field/RenderSafeInjectionTest.php:187 temp-dir prefix `candyforms-fp-inj-` → R4 rewrites to `sugar-forms-fp-inj-` (string literal, zero test logic). <!-- @rename-keep -->
- Ledger policy (B1 precedent): TAG baseline rows + CONTEXT.md history bullets + prior-lane NOTES lines; REWRITE docs/plans/* ledger narratives (candy-git precedent proves rewrites flow there) + prompt_kit/findings campaign pointers. <!-- @rename-keep -->

## Steps

- R0 tag-prep: baseline :37/:79, CONTEXT.md 13 untagged history lines (590,879,927,941,942,943,961,987,988,1018,1019,1354,1356), rename-2.2:34,85,88 + rename-2.3:26 + rename-4-B4:16 tagged. Baseline gate figures measured in-tree pre-R3.
- R1 dry-run:
- R2 remote rename:
- R3 tool run (EXACTLY ONCE):
- R4 manual: formerly-notice, keywords+huh, gen-docs comment fix, followups entry, glue sweeps.
- R5 wiring: root composer update sugarcraft/sugar-forms --no-install + refresh-deps linked (forms+consumers) + gen-docs + --check + gen-ansi --check.
- R6 gates: residue 0 BEFORE staging; lib suite EXACT; consumers full-suite (timeout 600); crush sharded K=8 CONSERVATION; tools/tests 426T/1484A; path-repos + child-lifetimes rc0.
- R7 commit + renames.json (status renamed + fold-line new bump) + push rename/c10 ONLY.

## Gates log

(pending)
