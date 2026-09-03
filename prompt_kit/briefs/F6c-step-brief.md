# F6c - residual citation sweep (gap-filler) - STEP-LEAD BRIEF

## Process rules (non-negotiable)
- **Lead never fixes.** You implement and verify; every dirty review cycle goes to a DEDICATED FIX AGENT with its own subdir `/home/sites/prompt-scratch/F6c/fix-N/`. You verify each fix commit by your own measurement, then spawn the NEXT fresh read-only reviewer (never shown prior findings). Cap 3 cycles; escalate at cap, do not grind.
- **IDENTITY LAW:** every commit's author AND committer must read literally `Joe Huss <detain@interserver.net>`. Write it out in full from this sentence - NEVER copy any bracketed or sanitized token from your context. Verify `%an <%ae> %cn <%ce>` after EVERY commit.
- Commit to your branch immediately; never leave work uncommitted. No merges, no pushes, no `git config` outside your scratchpad, no `composer`, no `caliber`.

## Sandbox
- Worktree `/home/sites/prompt-step-F6c`, branch `prompt/F6c`, created by the orchestrator off post-F6b master. Vendor is `cp -al` seeded. Verify `git -C /home/sites/prompt-step-F6c status --porcelain` is EMPTY and re-derive the tip sha.
- Scratchpad: `/home/sites/prompt-scratch/F6c/lead/` (yours). Private subdirs only; `rm -rf` only inside your own subdir.

## Mission
F6b (merged) refreshed four citation groups and REPORTED this residue as out of its scope. You sweep it. **All line numbers below were written against pre-F6b files and are UNVERIFIED pointers - re-derive every one by content-grep in YOUR tree before touching anything.** Targets:

1. `sugar-crush/tests/Providers/ProviderRequestResponseTest.php` - stale emit-literal citations around :44-50, :537, :574, :635, :655. They name per-provider usage-emit sites: `BedrockProvider.php:364-367`, `SglangProvider.php:1152`, `CustomProvider.php:389`, `OpenAIProvider.php:257` (per F6b's lead, current locations were roughly :571/:1271, :488, :357/:1050/:384/:246 - RE-DERIVE, these may themselves have drifted). Fix the citations to measured truth (comment-only).
2. `prompt_plan.md` region near L1604-1605 (this lives INSIDE the F6b-amended block - locate by content, e.g. the sentence citing `Bootstrap.php:1887` and the `Chat.php` four-cite group :7725/:7820/:7842/:7844) - same rule F6b used for its own plan edits: keep the original numbers visible, append the measured truth in-line, rule-42 three-part style where the record deserves it.
3. `sugar-crush/tests/Renderer/StatusLineSegmentTest.php` - the `transcriptSignature()` helper's docblock claims PREPEND / REPLACE / DROP all move the signature, but only the APPEND shape has a known-positive control (the fix8B-era observation, recorded in the travel ledger). Add a same-count REPLACE known-positive control: inside the zero-transcript test (or a sibling test if cleaner), plant a tick arm that REPLACES one transcript entry with a different one (count unchanged), prove the signature assertion reddens with its own message, then prove the control is real by showing the unmutated run green. Line-count neutrality does NOT apply to this file's test additions if a new test method is added - but then the roster `testFiles` figure stays (same file) while method counts may move: re-run the nine-file census + roster test and report any moved figure old->new in the SAME commit with the reason.

## Constraints (proven, not asserted)
- Comment/string-only changes. Per-file `token_get_all` elementwise identity proof BEFORE/AFTER (comments+whitespace stripped) - include the counts.
- **Per-file LINE COUNTS MUST NOT CHANGE** (GlobFigureDriftTest counts lines; SymbolCitationDriftTest walks citations and may re-balance its census on comment text). If a guard figure legitimately moves, report old->new with the per-class reason in the same commit.
- No new files, no deleted files (roster derivation 67/83/181/440/0 must stay). Goldens byte-identical (`32ea749d84938811ac9331419cae7380` / `ef0326dd38535aaa2f1d715919bff26e`).
- Deletion experiments per the §1.11 bar where applicable: for any citation a guard test polices, prove the pin reddens when the cite is re-rotted.

## Verification (focused only - orchestrator gates full suite)
- cwd `/home/sites/prompt-step-F6c/sugar-crush`, serial, `</dev/null`, box-quiet probe `ps -eo cmd | /usr/bin/grep -c '^php .*phpunit'` prints 0 before each run.
- `vendor/bin/phpunit tests/Providers/ProviderRequestResponseTest.php tests/Providers/VertexProviderTest.php tests/SymbolCitationDriftTest.php` (state figures; base PRR 32/72, Vertex 153/397, SymbolCitation 7/3035 at F6b tip - re-measure base in your tree first if master moved).
- Nine-file census set (list at `tests/TreeWideGuardRosterTest.php:407-417`): expect 176/31461 at post-F6b master - names its tree.
- `php tools/check-path-repos.php --no-lib-path-repos` FROM THE REPO ROOT, exit 0.

## Review loop
When implementation is committed: spawn a fresh READ-ONLY reviewer (task coder, no edit rights; may mutate the worktree only for experiments and must restore + prove porcelain 0). Brief it by FILE with the diff base/tip, the F6c brief path, and the step's own claims-to-attack (line counts, token identity, ledger completeness, guard moves). Findings arrive as a file at `/home/sites/prompt-scratch/F6c/review-N/findings-cycle-N.md` AT RECEIPT. Then a dedicated fix agent, then a fresh reviewer. NO FINDINGS must account for the 19 §1.4 checks (prompt_plan.md ~L366-501).

## Report (<=60 lines)
1) diff base..tip sha list + identity proof; 2) per-file before/after ledger with the old->new citation pairs; 3) proofs (line counts, token identity, goldens, guards); 4) focused figures with cwd+tree+serial+</dev/null; 5) review ledger; 6) brief claims measured FALSE (report, do not silently fix); 7) anything left for the travel ledger.
