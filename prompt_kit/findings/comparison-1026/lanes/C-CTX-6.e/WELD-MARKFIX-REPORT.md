# C-CTX-6.e — bookkeeping weld mark (WELD-MARKFIX) 2026-10-30

VERDICT: DONE — content was already on master; this weld applies the missing §4 mark + run-state fold. No cherry-picks, no lane worktree touched.

Disk proofs (same shell, cat-file/grep-verified):
- master tip at start: 740c29868; branch master.
- `git merge-base --is-ancestor 9c59bc8d1 master` OK; same a7627584b OK.
- `git log --oneline 9c59bc8d1~1..a7627584b` = exactly a7627584b (slice 2: tests/docs/report) + 9c59bc8d1 (slice 1: wire+pump+render).
- Deliverable live at master: `git show master:candy-code/tests/Chat/RecallNoticeRowTest.php` (header verified).
- DUR rows ALREADY committed (no mint): grep of committed `master:scripts/parallel-tests-durations.tsv` — RecallNoticeRowTest 0.059/9 (count 9 verified live by targeted run), RuntimeNoticeSinkTest 0.128/35, MemoryRecallBlockTest 0.059/14.

Gates (cwd candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe):
- step filter RecallNoticeRowTest|RuntimeNoticeSinkTest|MemoryRecallBlockTest: Tests: 62, Assertions: 270, OK (2 PHPUnit deprecations = foreign standing family).
- RecallNoticeRowTest alone: Tests: 9, Assertions: 37 OK (proves DUR count row).
- guard trio DocFigureProseDrift|DuplicatedTestHelperDrift|GlobDialectDifferential: Tests: 119, Assertions: 4747, OK.

Mark: plan_comparison.md :3119 flipped `#### C-CTX-6.e — ...` -> `#### ✅ a7627584b C-CTX-6.e — Structured, expandable recall row` + one-line dated Weld 1030BOOKKEEP note disclosing slice-1 9c59bc8d1 and the phantom-claim correction. Heading-mark census 279 -> 280.

Run-state fold (re-read live pre-fold, sha1 57b4a9f7...): welded += C-CTX-6.e, welded_count 279 -> 280, assert len(welded)==welded_count==280 PASSED; inflight kept as-is (C-SES-8.g, C-TUI-5.a at build); backup run-state.json.bak-ctx6e-markfix; tmp+os.replace.

Commit staged paths ONLY: plan_comparison.md, prompt_kit/findings/comparison-1026/lanes/run-state.json (durations.tsv unchanged — not staged). NO push (rides wave-close). No caliber refresh.
COMMIT SHA: 671105299 (cat-file confirmed; 2 files: plan_comparison.md + lanes/run-state.json; durations.tsv unchanged/not staged)
