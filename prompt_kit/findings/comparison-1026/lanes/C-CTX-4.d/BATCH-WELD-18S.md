# BATCH-WELD-18S — welder-18s crash journal (live)

BATCH: single step C-CTX-4.d (W23 member). NO PUSH (no wave closes).

## Step 0 — re-anchor (DONE)
- master == origin == 574e69f1d (individual rev-parse; branch --list 'weld*' empty).
- Chain cat-file verified: 2571cb466 / fa74e3a7a / 6c86300ea / 9c6ad8b73 all commit objects; base d9a3dc110.
- cc/C-CTX-4.d tip == 9c6ad8b73, worktree /home/sites/cc-cmp-C-CTX-4.d porcelain CLEAN.
- 4/4 %ae detain@interserver.net (git log format check).
- Verdict files: lanes/C-CTX-4.d/REVIEW-2.md 5627B VERDICT: CLEAN @20:18 (REVIEW-1 old CRITICAL superseded — pre-cure state, kept).
- run-state weld_pending C-CTX-4.d: tip 9c6ad8b73; DUR rows PublicUrlGuardTest 17T + InstructionUrlSourceTest 7T; NIT carry transferPinned short-fread; welded_count 239.

## REVIEW-2 gate shapes (reference)
- 'PublicUrlGuardTest|InstructionUrlSourceTest' = 23T/205A OK (17+7).
- Broad guard filter incl ReadPathCensus: sole red = foreign RemoteSessionPush staleness (allowed/disclosed; spawn allows it).
- ContainedPathInventory 2F = foreign welded-file carry (NOT in spawn filter list).

## Plan of record
1. weld-18s off 574e69f1d; cherry-pick 2571cb466 fa74e3a7a 6c86300ea 9c6ad8b73 (zero-conflict expected; conflict = both-intents resolve or STOP+report).
2. Gates cwd candy-code: --filter 'PublicUrlGuard|InstructionUrlSource|WebFetch|ToolSecurity|ReadPathCensus' (expect 1 foreign RemoteSessionPush red); five-guard (staleness pair ONLY red); gens gen-protocol-schema --check + command/tool/settings docs --check (no protocol in chain — verify); path-repos + child-lifetimes rc0; vitest N/A unless web files moved (verify git diff --stat). NEVER PtyRunToolTest::testExpectMissIsLoudAndTheScriptStillContinues.
3. DUR: 2 rows (PublicUrlGuardTest 17T, InstructionUrlSourceTest 7T) — T/A derive from green run; separate scripts commit (batch-18q precedent).
4. Meta 'weld: C-CTX-4.d (batch-18s; DUR x2; run-state)': plan §4 ✅ mark + lanes/C-CTX-4.d/ evidence + run-state fold (preserve inflight verbatim, welded += C-CTX-4.d, weld_pending key cleared, tip=meta, batch18s_note).
5. Backup lead run-state to /tmp/opencode/lead-1029/run-state-pre-ff18s.json; ff main tree master->weld-18s tip; NO PUSH.
6. Prune cc/C-CTX-4.d after git-cherry 4/4 '-' proof; keep weld-18s for audit. Stale-registration glance: /home/sites/cc-cmp-C-SES-7.c worktree + cc/C-SES-7.c-tmp phantom branch (prune stale registrations first; leave if used by main worktree).

## Log
- PICKS DONE (zero conflicts): 2571cb466->ab58c2dbc, fa74e3a7a->13bc002f1, 6c86300ea->692fc8803, 9c6ad8b73->eb431e67c (final). 4/4 %ae+%ce detain, zero Co-Authored trailers (greps 4/0).
- DIFF CENSUS: exactly 9 files, zero src/Protocol + zero candy-code-web paths -> no regen riders, vitest N/A (verified).
- GATES (cwd candy-code, plain pipe, env -u LINES -u COLUMNS):
  * --filter 'PublicUrlGuard|InstructionUrlSource|WebFetch|ToolSecurity|ReadPathCensus': Tests 208 / Assertions 1321 / F1 = foreign ReadPathCensus RemoteSessionPush row ONLY (allowed/disclosed).
  * new-files direct run: OK 23T/205A. junit per-file: PublicUrlGuardTest 16T/178A + InstructionUrlSourceTest 7T/27A (spawn cite 17T was an overcount — green-run authoritative, batch-18q precedent).
  * five-guard (ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift): 134T/17223A, F2 = staleness pair ONLY (pinned 25,537 vs live 28,471 — §7 closeout re-pin carry).
  * 'MemoryDocumentationDrift|ReadmeSettingsTierClaim|ContainedPathInventory': 63T, F2 = ContainedPathInventory roster pair — foreign welded-file carry per REVIEW-2 ("staleness 3 foreigns stay uncharged").
  * gens x4 --check: all "up to date" rc0. check-path-repos rc0; check-child-lifetimes rc0 (33 sites/9 findings/9 accounted/0 problems).
- DUR x2 MINTED: descending-insert sorted; tsv 1630->1632 rows (PublicUrlGuardTest 0.059/178, InstructionUrlSourceTest 0.014/27). Pre-existing tail non-monotonicity at 1622-1630 proven untouched.
- NEXT: scripts commit (tsv+journal) -> plan §4 mark + run-state fold -> meta -> backup lead run-state -> ff main tree (NO PUSH) -> cherry proof 4/4 + prune cc/C-CTX-4.d -> stale-registration glance (C-SES-7.c worktree; cc/C-SES-7.c-tmp branch).

## Meta stage (this commit)
- §4 mark ✅ eb431e67c C-CTX-4.d (full pick map + gate figures inline).
- run-state fold validated (json parses; welded 240 sorted/set/len==count; weld_pending {}; tip:=4f1bdab31, meta=+1 self-hash law; inflight 10 keys verbatim; batch18s_note added). Lead pre-fold backup: /tmp/opencode/lead-1029/run-state-pre-ff18s.json md5 0e0afabadb11a4a2f0575ab75fff14db.
- Meta carries: plan_comparison.md + lanes/C-CTX-4.d/{BRIEF,REPORT,REVIEW-1,REVIEW-2,BATCH-WELD-18S}.md + lanes/run-state.json.
- NEXT after meta: re-check lead md5 pre-ff (fold law) -> checkout master -> merge --ff-only weld-18s -> NO PUSH -> git-cherry 4/4 '-' -> prune cc/C-CTX-4.d worktree+branch -> stale-registration glance (/home/sites/cc-cmp-C-SES-7.c, cc/C-SES-7.c-tmp) -> report.
