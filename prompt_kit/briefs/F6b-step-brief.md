# F6b — citation-refresh sweep — STEP-LEAD BRIEF

## Role
Small maintenance step. Refresh rotted line-number citations across test comments, one
src docblock, and the plan records. You are the LEAD. PROCESS RULE: you NEVER apply review
findings yourself — a dirty cycle gets a DEDICATED FIX AGENT (own subdir
/home/sites/prompt-scratch/F6b/fix-N/); you verify its commits with your own measurements,
then spawn the NEXT fresh read-only reviewer. Fresh reviewer every cycle, never shown prior
findings. Hard cap 3 cycles (small step); escalate rather than grind.

## IDENTITY LAW (has blocked merges twice)
Every commit must carry author AND committer "Joe Huss <detain@interserver.net>" — WRITE
THAT ADDRESS LITERALLY. Never copy an identity-looking token from your context: a sanitizer
sometimes replaces it with a bracketed placeholder and agents have echoed that into commits.
Verify %an %ae %cn %ce after EVERY commit; the orchestrator byte-scans incoming objects.

## Sandbox
Worktree /home/sites/prompt-step-F6b, branch prompt/F6b, created by the orchestrator off
master 0fdfd9033, vendor hardlinked (cp -al). Never composer install/update. Never push,
never merge to master. porcelain must be 0 whenever you hand off; commit immediately after
each coherent change (never leave work uncommitted).

## Base figures (measured at master 0fdfd9033; do not re-measure the base)
Full suite (cwd checkout root, serial, </dev/null, box-quiet probe
`ps -eo cmd | /usr/bin/grep -c '^php .*phpunit'` = 0): Tests 10644 / Assertions 165010
(MMG-198 arm; 165013 at the 201 arm — never adjudicate the 3 by headline) / Skipped 1 / 0 fail.
Nine-file census (cwd sugar-crush/): OK 176 / 31461. Roster derivation 67/83/181/440/0,
roster file 17/1101 — YOU ADD NO FILES, so any move is itself a finding. Goldens
32ea749d84938811ac9331419cae7380 / ef0326dd38535aaa2f1d715919bff26e must stay byte-identical.
Path-repo gate FROM REPO ROOT: php tools/check-path-repos.php --no-lib-path-repos -> exit 0.

## Scope — all numbers below are AS-RECORDED and EXPECTED-ROTTED; re-derive each by
## CONTENT-GREP at your tip, never by arithmetic from the record. Cite by function/table
## NAME plus line number wherever possible (the durable form).
1. sugar-crush/tests/RuntimeTest.php — a comment/assertSame-message region (~:6929-6942 as
   recorded) carries self-citations into the same file: a ":4001-4003" range pointing at the
   write-primitive blind-spot table, a ":3921" citation, and a list ":4931/:4967/:5156"
   recorded as pre-rotted (targets are the "php -l clean and RUN" doctrine sites ~:4958/:4986,
   :5022, :5211 at last derivation). ONE of these sits inside an assertSame() MESSAGE STRING
   — treat it as a disclosed string change, not a comment. Verify each target by reading it.
2. sugar-crush/src/Providers/VertexProvider.php ~:331-332 docblock quotes pre-P4.S2 emit
   literals that no longer match what the code emits. Comment-only refresh.
3. sugar-crush/tests/Providers/ProviderRequestResponseTest.php ~:46 and ~:686 cite
   VertexProvider.php:904-919 for the usage-event reads; the truth (last measured) is the
   reads now live ~:1006-1019. Comment-only refresh.
4. prompt_plan.md:1606 and the §18 escalation row (~:3480) cite WorkflowEngine loop lines
   :875/:1105; re-derive the current `foreach ($workflow->stages` / nested-stage loop lines
   (recorded ~:895/:1108/:1126) with git grep -n and refresh BOTH citation sites. Keep the
   ORIGINAL numbers visible as history (rule 42 three-part: what it said / the measured
   truth / how measured) — prompt_plan.md is a record file.

## Constraints
- EVERY PHP-file change is comment/string-only: prove per file with token_get_all
  significant-stream ELEMENTWISE identity (strip T_COMMENT/T_DOC_COMMENT/T_WHITESPACE) —
  paste the comparison output into your report — plus php -l clean.
- KEEP per-file LINE COUNTS UNCHANGED (a census-scanned file's line count moves
  GlobFigureDriftTest; replace lines in place, never add/delete lines in sugar-crush files).
- NEVER weaken/skip/rename/delete a test; §1.10: touch no dormant code logic.
- For each refreshed citation, paste the target line(s) as evidence the new numbers hit.
- Scratchpad: ONLY /home/sites/prompt-scratch/F6b/lead/ for your files; private backups
  inside it; rm -rf never outside it. No git init/config outside the sandbox scratchpad.

## Verify before finishing (cwd sugar-crush unless noted; serial; </dev/null)
vendor/bin/phpunit tests/RuntimeTest.php; tests/Providers/ProviderRequestResponseTest.php;
tests/Providers/VertexProviderTest.php; tests/SymbolCitationDriftTest.php; then the nine-file
census set; expect figures equal to base (line counts unchanged) or explained in the SAME
commit with an honest old->new statement. NO full suite — the orchestrator gates.

## Review loop
Commit your work -> spawn ONE fresh READ-ONLY reviewer (task-scoped: no edits, may mutate
for experiments with checkout+porcelain-zero proofs): give it this brief, your diff, and the
§1.4 nineteen-check list (prompt_plan.md ~L366-501 — re-derive); its findings go to
/home/sites/prompt-scratch/F6b/review-N/findings.md AT RECEIPT. Findings -> dedicated fix
agent -> fresh reviewer. NO FINDINGS must account all 19 checks with evidence.

## Final report (<=60 lines)
Per-citation table old->new with evidence; elementwise-identity proofs; focused figures with
cwd; identities of your commits; anything found outside scope (REPORT, never edit).
