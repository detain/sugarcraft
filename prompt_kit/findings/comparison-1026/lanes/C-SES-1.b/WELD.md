# C-SES-1.b — WELD log (incremental, REPORT-REAL style)

Base master: 307ffb44f. Lane: cc/C-SES-1.b single commit 15b274513e016c9a0c9527b224c232d48378ea88 (cut off b985fbe51).
Preflight: `git branch --show-current` = master; tree dirt confined to `prompt_kit/findings/comparison-1026/lanes/` (+ stray root `run-state.json`, left unstaged per brief).

## Step 1 — cherry-pick
- LANDED: master 46d64b18a (pick of 15b274513, 2 files +35/-2, ZERO conflicts — `BackgroundSession.php` and `BackgroundResultAnnouncedTest.php` untouched by the C-SES-9.b weld, as briefed).
- Author law on pick: %ae grep -c detain = 1; %ce grep -c detain = 1 (separate greps); `%(trailers)` scan empty.

## Step 2 — regeneration
- NONE run: lane diff touches no protocol / generated-docs / dist surface (src + test file only). `suite-figure.json` / `parallel-tests-durations.tsv` untouched; no new `*Test.php` (test file pre-existing → no durations row owed).

## Step 3 — merged-tree gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS, -c phpunit.xml)
- `--filter 'BackgroundResultAnnouncedTest'` -> OK: Tests: 15, Assertions: 73, PHPUnit Deprecations: 2 (class-ambient `AccessorDeprecationTest` providers). EXACT match to REVIEW-1's lane-tree figure; brief's "~16T" was an estimate — builder and reviewer both measured 15T, merged tree confirms 15T.
- `--filter 'SpendLedgerTest'` -> OK: Tests: 15, Assertions: 66, PHPUnit Deprecations: 2. EXACT match to REVIEW-1.
- Full suite NOT run (§1.5); `PtyRunToolTest` not in any filter; standing figure-staleness pair left alone (closeout §7 duty).

## Step 4 — absorption proof
- `git cherry master cc/C-SES-1.b` -> `- 15b274513...` (absorbed, patch-id identity).
- `git patch-id --stable`: lane 15b274513 and pick 46d64b18a -> `d4bb9137569b131a4721934666f1e7e7fd8d23bc` (identical).
- Content check: `git diff master cc/C-SES-1.b -- <both welded files>` -> 0 lines (byte-identical on master).
