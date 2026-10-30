# REVIEW-2 — lane cc/CI-1030 (round 2: fixer-cure verification)

VERDICT: FINDINGS — 1 CRITICAL (fixer round is a PHANTOM: zero repair landed) / 2 MAJOR (r1 findings still reproduce, disk-proved below) / 2 MINOR (carried unchanged).

## CRITICAL-1 — the fixer left no trace on disk
- `git -C /home/sites/cc-cmp-CI-1030 rev-list --count 616d27864..HEAD` = **7** — the ORIGINAL lane commits only (5d66a216f..d9c381689, atop old base 59ea47407). Tip d9c381689 commit-time **13:33:44**; REVIEW-1.md mtime **13:52** — the review POSTDATES the entire branch history, so no fix could have been authored in response to it.
- `git reflog show cc/CI-1030`: exactly the 7 commits + branch creation. No amends, no later commits. Worktree `git status --porcelain` CLEAN — no uncommitted repair either.
- `FIX-1.md` DOES NOT EXIST: not in `/home/sites/sugarcraft/prompt_kit/findings/comparison-1026/lanes/CI-1030/` (dir holds only REVIEW-1.md, 7601B), not in the lane worktree copy (holds only REPORT.md, 12302B = committed blob), nowhere under /tmp/opencode (latest ci-1030 artifact 13:33; `phpstan-final.txt` 13:32 is the BUILDER's run, predates the review).
- cc/CI-1030-review (the kept merged-shape branch, tip 9d21718f6 @ 13:37:09) = master + 7 picks, no fixer commit.
- Every r1 finding re-checked on HEAD and STILL PRESENT (proceeds below). The brief's fixer claims ("re-ran phpstan on merged tree", "pin ADDED", "erratum applied", "mark added") are FALSE TO DISK.

## MAJOR-1 (r1, unfixed) — SelfUpdateTest roster still 12 on merged shape
- HEAD `candy-code/tests/Cli/SelfUpdateTest.php:287` = `testTheRosterCarriesTwelveVerbsWithSelfUpdateInAlphaOrder`, expected list 12 entries (`...'session', 'usage'`), lsp ABSENT (grep-proved both lane branches; also zero 'lsp' in the file).
- Truth on current master 616d27864: `src/Cli/ParsedArgs.php:56` SUBCOMMANDS = **13** verbs incl BOTH 'lsp' and 'usage' (sorted list identical to the 12 plus 'lsp' at index 6).
- Note (new this round): master's OWN copy still says **ElevenVerbs** with an 11-entry list — a pre-existing standing red at 616d27864 independent of the lane; the lane partially cures it. Fixer must set **ThirteenVerbs** on the lane (rename method, docblock word-number, 13-entry list incl 'lsp') derived from the MERGED tree.

## MAJOR-2 (r1, unfixed) — phpstan-baseline userConfig count still impossible
- HEAD `candy-code/phpstan-baseline.neon`: the `nullsafe.neverNull` / `?->userConfig` block for src/Chat.php still carries **count: 5**.
- `git grep -c '?->userConfig'` = **4** on lane HEAD AND on master 616d27864 Chat.php — 5 can never fire; PHPStan 2.1 count-validation keeps CI red. Fix to the true merged-tree number, then RE-DERIVE the whole 39-file baseline against the merged shape (fixer never ran phpstan post-review; builder's 13:32 run predates r1's merge set).

## MINOR-1 (r1, unfixed) — App.php SessionTab::$name fix has no regression pin
- Zero new test files in the range; `git log --all --since tip-time -- candy-code/tests/` shows only the lane's own 7 subjects (+ their review-branch twins). Pin still owed (or weld rider). If it becomes a NEW test file, DUR duty lands on the welder.

## MINOR-2 (r1, unfixed) — REPORT.md durations falsehood uncorrected
- HEAD:REPORT.md grep for '1135|1256|erratum' = zero hits. The claim "MediaSettingsTest/MenuBarLabelDerivationTest have no durations.tsv row" stands uncorrected (rows DO exist at base lines 1135/1256).

## §4 mark — absent, confirmed
- `grep -c 'CI-1030' /home/sites/sugarcraft/plan_comparison.md` = **0**; no anchored `^#### ✅ <sha> CI-1030` line in either tree. Ad-hoc lane mark still owed at weld (path-scoped `git commit -o -- plan_comparison.md`, 'ad-hoc owner lane' wording per r1 §7-3).

## Sweep (fixer-round trivial results)
- Forbidden files: untouched — trivially (no fixer commits exist). Lane's original 7 already zero-touch on suite-figure.json/durations.tsv/dist (r1 diff-proved).
- Author law: no new commits to audit; original 7 verified detain x7, zero Co-Authored (r1).
- Merge-tree vs master: unchanged shape — 7 picks apply clean (r1 real cherry-pick proof @ 9d21718f6 kept as the merged tree for the fixer).

## Disposition
Do NOT weld. REPAIR = fresh disk-grounded fixer on cc/CI-1030 (do NOT resume the phantom session): (1) ThirteenVerbs cure; (2) baseline userConfig count→4 + full re-derivation on merged tree, phpstan run must show 0 unmatched; (3) SessionTab pin; (4) REPORT erratum lines; (5) commit FIX-1.md into the lane evidence dir before returning. Then round-3 review re-runs: batch-1 set, phpstan, merged tree gates; welder note carries r1 §7 riders VERBATIM (durations stale paths master 937/966 → Skills/* kills 2/4 shards; staleness pair closeout duty; ad-hoc §4 mark; candy-code-web eslint/composer-SIGSEGV + Codacy/codecov external) + this round's new datum: master's own ElevenVerbs standing red is cured BY the lane's 13-verb pick, disclose in weld note. Collect REVIEW-1 (7601B) + REVIEW-2 + REPORT (12302B) pre-prune; FIX-1.md pending existence.
