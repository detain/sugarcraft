# C-TOOL-6.a FIX-2 — round-2 fixer report

RESULT HEADER (final)
- Verdict: ALL FINDINGS CURED — MAJOR-1 + MINOR-1/2/3 + NIT-1 fixed & gated; NIT-2 routed to closeout.
- Tip before fixes: c79073e20 · NEW TIP: 5a42eb62207d0cacbe99b1ea684883d90f46b07d (git cat-file -t = commit)
- Commit: `C-TOOL-6.a: review fixes (round 2)`, 2 files +16/-5 (candy-code/docs/MCP.md 9/5,
  tests/Config/McpBrowserPresetDocTest.php 7/0). %ae grep-c detain =1, %ce grep-c detain =1,
  Co-Authored = 0. Sibling dirt (BinCandyCodeDispatchTest M, UsageSubcommandTest ??) NOT staged, NOT touched.
- Mutation proof MINOR-1: block-1 playwright loosened to @latest (block-2 pinned).
  BASE test on mutated doc = 4T/89A GREEN (the reviewer's hole, reproduced);
  FIXED test on mutated doc = 4T/101A 2F red naming 'playwright' + "tier blocks disagree"
  (tests 2 and 3 — both build entries); doc restored via cp + md5sum -c OK → fixed test 4T/101A green.
- Gates as-run (cwd=candy-code, env -u LINES -u COLUMNS, plain pipe, PtyRunToolTest never in a filter):
  * McpBrowserPresetDocTest|DocFigureProseDriftTest = 96T/4239A OK (+12A = the new per-tier asserts, 6/call × tests 2,3)
  * own file = 4T/101A OK (was 89A pre-MINOR-1)
  * McpForeignTranslateTest = 16T/35A OK · bridge/shape trio = 28T/81A OK (both match reviewer exactly)
  * doc-guard sweep = 194T/45792A OK. Reviewer read 193T/45778A — PROVEN not-lane-attributed:
    base c79073e20 with sibling dirt restored (explicit-path stash push/pop of MY 2 files only) =
    194T/45780A, i.e. the +1T/+2A comes from the sibling's uncommitted UsageSubcommandTest feeding a
    provider-driven guard; my fix adds exactly +12A.
- php -l on the touched test file: no syntax errors. No glob-shaped literals added (GlobDialect green in sweep).
- FIX-2.md + REPORT.md NIT-fix live in prompt_kit/findings/comparison-1026/lanes/C-TOOL-6.a/ — untracked
  evidence, never staged (write map honored for the branch is the welder's job, per REVIEW-1 §scope).
- INCIDENT (disclosed): a second verification `git stash push -- <my 2 files>` was a NO-OP (tree
  already == committed tip), so the chained `git stash pop` reached the REPO-GLOBAL stash stack and
  tried to restore another lane's stash@{0} ("WIP on cc/C-CTX-5.c: recovered after mis-pop ...").
  Git ABORTED it cleanly (untracked CacheTtlIdleFoldTest.php already on disk; entry not applied,
  not dropped — `git stash list` + `git status` re-verified byte-identical to the pre-command state,
  committed docs md5 matches backup). Zero side effects; stash@{0}/stash@{1} left untouched.
  Lesson recorded: after committing, stack-compare is done by throwaway-worktree, NEVER stash
  push/pop in a worktree that may share the repo's global stash.
  (+12A decomposition stands on the FIRST, valid round-trip: base 194T/45780A vs tip 194T/45792A.)

## Findings cured (authority: REVIEW-1.md in the MAIN TREE, 12,227 B)

### MAJOR-1 — docs/MCP.md import bullet overstated the trust gate
Code-verified facts used for the cure (NOT a second doc claim):
- `McpDecision::trustProjectMcp()` fingerprints the PROJECT `.mcp.json`
  (`$decision['path']`) into per-root records under `~/.candy-code/mcp-trust.json`.
- The page's own importer section (:225-238, verified live): the verb is
  print-only by default (E710); `--write user` appends into `~/.candy-code/mcp.json`;
  `--write` any other tier incl. project exits 2 having attempted nothing;
  user-tier entries "start at every future launch ... with no project trust pin".
- So neither the default path (nothing lands) nor the only writable tier (user,
  unpinned) is covered by the gate. Cure prose accordingly.

### MINOR-1 — tier-block merge was last-write-wins
tests/Config/McpBrowserPresetDocTest.php:107 — add per-name entry-equality assert
inside the merge so a loosening in EITHER block reddens; mutation-proven.

### MINOR-2 — annotation wording vs code
docs/MCP.md:649 "openWorldHint not set" -> bridge :261 is `openWorldHint() !== true`
(verified live); wording flipped to match, consistent with the page's own :941.

### MINOR-3 — dropped forward pointer
Design (design-tool.md:735) says "outside the Bash jail (until C-TOOL-4.e)".
The landed bullet lost the step id; restored (page already carries C-MCP-1/E710 ids).

### NIT-1 — lane REPORT.md env claim
REPORT.md :27/:67 claim the preset entries carry command+args+env; the section's
blocks carry only {command,args} (grep of the new section for `"env"` = 0, verified).
REPORT corrected in place (untracked evidence, never staged).

### NIT-2 — routed to closeout
(The review's second NIT is the builder's non-reproducible md5-restore claims in its
report; no artifact change warranted — welder/closeout note.)

## Weld carries (for a later welder)
- New test file real carry at FIX-2 tip: **4T/101A** (89A at c79073e20; +12A from the
  MINOR-1 per-tier asserts, 6 assertions each in tests 2 & 3). Lead's earlier "+1T/+52A"
  was wrong at every tip. K=24 battery + refresh-suite-figure at weld remain authoritative.
- durations.tsv row MINT for candy-code/tests/Config/McpBrowserPresetDocTest.php (lane-forbidden file).
- This lane MUST be CHERRY-PICKED, never adopted/tree-copied: the +126 MCP.md
  section is NOT on master (git show master:candy-code/docs/MCP.md | grep -c
  'Browser control and computer use' = 0, lead-verified). Pick range: 07e49e872..5a42eb622
  (c79073e20 then 5a42eb622, or squash-pick the tip's content — same 2 files).
- Landed-blob proof after the pick (REVIEW-1 §1): grep -c 'Browser control and computer
  use' = 1 on the welded MCP.md. REVIEW-1's predicted 79,797 B blob was computed for the
  OLD tip; this round nets +4 lines (9 add/5 del), so re-derive the expected size from
  the picked blob rather than trusting that numeral.
- MAJOR-1 was a SECURITY-section doc truth flip — disclose in the COMPARISON/plan ledger
  (cured pre-weld on-branch, not in-weld).
