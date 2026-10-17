# rename-5-E2-weld REPORT — candy-core → sugar-core fold (2026-10-16) <!-- @rename-keep -->

Sole master writer. NEVER pushed force. oc.7z/foreign untouched.

## Chain
- Base master == origin == `47d721d95` (E R8/R9 closeout; ancestor of d1396a35c chain confirmed).
- Lane `rename/e13` local==origin==tip `b85ec70d1` (cf8a3eb04 rename + fb9a23e06 tag-prep), merge-base d1396a35c.
- Weld chain: `6fd21a479` merge --no-ff → `4e5d6d455` lock refresh → `04018b294` heal → `0dccf9584` tracker → `*` report. All author-law A/C detain %ae/%ce==1, no Co-Authored-By.

## Conflicts (1 total)
- `tools/renames.json` UU — take-both union: HEAD's 12 E lines `packagist-done` kept, theirs' candy-core line adopted as `renamed`; pending-core line on HEAD side dropped. JSON validated via php json_decode (keys $comment,entries; E rows 13 = 12 packagist-done + 1 renamed) <!-- @rename-keep -->

## Shared-doc union audit (theirs-append drop check)
- `prompt_kit/briefs/rename-5-e13/NOTES.md`: +41 lines from e13 — survived (post-merge diff vs origin/master shows all 41 present).
- `prompt_kit/briefs/rename-followups.md`: +10 lines from e13 — survived.
- `plan_rename.md`, `rename-5-E-weld/R8R9.md`: e13 made ZERO changes (no theirs sections at risk); tracker row added by weld post-merge.
- docs/repo_map/*: pure renames (R status), no conflicts. `composer.lock`: took OURS, then ONE `composer update sugarcraft/sugar-core --no-install` (lock: 61 old candy-core ref lines removed, 58 sugar-core refs + path url `sugar-core` present; dev-master reference-hash churn across siblings is composer's normal path-repo re-read, scope accepted per playbook) <!-- @rename-keep -->

## Residue heal
- First run `--all-done`: 17 findings (6 unique lines), ALL R3 historical prose in E-weld REPORT.md (:8,:12,:18), R8R9.md (:44,:77), e13 NOTES.md (:16). Zero live-code. Healed with ` <!-- @rename-keep -->` house suffix → **0 findings** (commit 04018b294; also 0 after tracker insert).

## Gates (committed tip)
0. Husk `candy-core/` present, `git ls-files`==0 → rm -rf. Dangling `*/vendor/sugarcraft/candy-core` symlinks: 57 tree-wide (incl root vendor + docs/cookbook) → hand-pruned all. refresh-deps --mode=linked sugar-core(3/3)+sugar-crush(23/23)+sugar-forms(10/10)+sugar-pty(8/8) after prune: escaping=0 all, committed-tree guard rc0, tracked per-lib locks 0 <!-- @rename-keep -->
1. `check-rename-residue.php --all-done` → **0 findings**.
2. sugar-core suite: **1401T / 28944A / 28S** OK — EXACT vs lane-recorded figures.
3. crush K=8 (repo-root invocation): 8/8 shards rc=0; sums **22,564T +0 EXACT / 423,770A (+118A vs pinned 423,652 — same wobble E-weld block and e13 lane both recorded; figure-arm conservation pins T/S/F/E, A-drift tolerated by design) / 2S / 0F / 0E**. No ChatTest flake re-run needed.
4. tools/tests: **429T / 1517A** OK EXACT via sugar-core/vendor/bin/phpunit.
5. path-repos --no-lib-path-repos rc0; child-lifetimes rc0 (60 libs, 28 sites, 8 findings/8 accounted incl flipped WorkerPool row); gen-docs --check rc0; gen-ansi-readme --check rc0.
6. sugar-forms deep-dep smoke: **2343T / 4564A** OK EXACT.

## Not done (by brief)
- candy-core R8/R9 (remote rename + packagist) — follow-up serial agent owns it <!-- @rename-keep -->
- Full serial figure re-pin — campaign end.

## Anomalies
- None beyond the disclosed +118A assertion wobble (pre-recorded by e13 and E-weld at the identical value).
