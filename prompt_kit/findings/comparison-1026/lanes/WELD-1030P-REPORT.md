# WELD-1030P report (welder, incremental)

Master at start: 80fe2275b (cat-file'd). Branch tips: cc/C-SEC-7.b @ 56aa4987f, cc/C-SEC-8.d @ 2ebd4b77a (both cat-file'd).

## Step 0 — preflight + range proof
- `git branch --show-current` = master; tip 80fe2275b.
- C-SEC-7.b range 484f313a9..56aa4987f = 3 commits (rev-list --count 3): f0db09a45 (code), 60e99cc20 (docs/census), 56aa4987f (r1 fixes). EXCLUSIVE start verified.
- C-SEC-8.d range aa5ddf638..2ebd4b77a = 3 commits (rev-list --count 3): f1d679d3d, 378435bb6, 2ebd4b77a. EXCLUSIVE start verified.
- run-state master_tip field stale (fd2224b4a); welded_count 266 → 268 after both lanes.

## Step 1 — picks (master, all zero-conflict, all A/C=detain verified)
- C-SEC-7.b: f0db09a45→c53e53046, 60e99cc20→f002c9e37, 56aa4987f→b927d39b8
  (NOTE: first multi-pick attempt aborted on untracked main-tree lanes/C-SEC-7.b/REPORT.md; sequencer state tangled; aborted to 80fe2275b and re-picked one-by-one — all clean.)
- C-SEC-8.d: f1d679d3d→6147b3904, 378435bb6→fcf112aa8, 2ebd4b77a→86ad79b52
- Main-tree lane evidence dirs set aside to /tmp/opencode/weld1030P/{sec7b-main,sec8d-main} pre-pick; branch REPORT/FIX-1 are the committed truth (lead note: never overwrite on-branch corrected REPORT.md with main-tree copy).

## Step 1C — protocol trio
- gen-protocol-schema.php --check (cwd candy-code): "protocol schema: up to date" — GREEN, zero schema bytes moved by both lanes → NO regen pair, npm untouched.
- gen-settings-doc --check: up to date rc=0 (C-SEC-7.b 52-row SETTINGS.md claim proven). gen-command-docs --check + gen-tool-docs --check: up to date.

## Step 1 post-pick gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS, merged tree @ 86ad79b52)
- RestrictedSafeModeFlagsTest: OK Tests: 14, Assertions: 84 (2 pre-existing PHPUnit deprecations, same shape as all filters). junit testsuite time 0.050848s.
- StderrEmitterCensusTest: OK Tests: 95, Assertions: 22639 (lead note 95T/22549A at lane; +90A merged-tree scan drift, GREEN = gate).
- SubmitInvisibleUnicodeTest (both classes): OK Tests: 12, Assertions: 43 — Chat 7T (0.012696s) + Host 5T (1.170270s). Reviewer-corrected counts confirmed.
- SymbolCitationDriftTest: OK Tests: 9, Assertions: 18332 (lead quoted 18250A at lane; tree-scan recount drift; cure proves on merged tree).

## Step 3 — three-proofs
C-SEC-7.b: (i) mark ^#### ✅ b927d39b8 C-SEC-7.b at plan:3021; (ii) master blob candy-code/tests/Cli/RestrictedSafeModeFlagsTest.php cat-file -e OK; (iii) landed blobs: ArgvParser '--restricted' x4 + 'safe-mode' present, Bootstrap.php:384/:2656 C-SEC-7.b choke gates, zero Theme.php touches in 80fe2275b..HEAD.
C-SEC-8.d: (i) mark ^#### ✅ 86ad79b52 C-SEC-8.d at plan:3368; (ii) master blobs tests/Chat/SubmitInvisibleUnicodeTest.php + tests/Host/SubmitInvisibleUnicodeTest.php cat-file -e OK; (iii) HOOKS.md:346 landed blob reads "from `submitDraft()` on every prompt" (rename proven in landed blob); Chat.php:9904-9938 stripInvisibleFormatting + submitDraft (x1), SessionHost x2.

## Evidence reconciliation
- C-SEC-7.b: branch-corrected REPORT.md (10790B, dated FIX-1 block) landed via picks; main-tree 9200B copy (lead copy, superseded) NOT used to overwrite — preserved /tmp/opencode/weld1030P/sec7b-main/. FIX-1.md (3272B) master-only, rode pick 56aa4987f. REVIEW-1.md (6902B) + REVIEW-2.md (6330B) main-tree-only → copied into lanes/C-SEC-7.b/ and committed in this weld batch (pre-prune collection done).
- C-SEC-8.d: REPORT.md main==master byte-identical; FIX-1.md (6444B) rode pick 378435bb6; REVIEW-1.md (6305B) + REVIEW-2.md (5178B) collected from main tree.
- DUR rows appended (append-only, tail of file, 1655→1658): RestrictedSafeModeFlags 0.051/14; Chat/SubmitInvisibleUnicode 0.013/7; Host/SubmitInvisibleUnicode 1.170/5. (Lane notes said ~2.1s Chat/~1.9s Host — merged-tree re-time measured lower; disclosed, measured values shipped.)

## Prune + absorption
(git-cherry proofs appended below)
