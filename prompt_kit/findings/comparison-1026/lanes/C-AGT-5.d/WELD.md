# WELD — C-AGT-5.d (batch-12, 2026-10-28) — WELDED onto master 965be930f

- Pick-map (`git cherry-pick -x`, all three clean — HookWebhooks.php/ServerConfig.php untouched
  between lane base 302e99ac2 and start-tip, so the batch-10 conflict worry did not materialize):
  44fae6291 → 3596fa061, 4bbcc0f6e → 60915338b, 18a64b8f5 → d3d4b54f4 (final pick; §4 cites it).
- The batch-10 STOP is CURED by the lane's own fix 18a64b8f5: `private const WEBHOOK_BODY`
  rename in tests/Server/HookWebhooksSignatureTest.php removes the byte-different BODY pair
  against foreign tests/Automation/WebhookSignerTest.php:23. Lead ruled no re-review.
- Gates on merged tree (cwd candy-code, env -u LINES -u COLUMNS, -c phpunit.xml, plain pipe):
  - `HookWebhooks|ServerConfigWebhooks|ServerProtocolDocumentationDrift`: 69T/2477A/0F/2D OK
    (T exact vs REVIEW-2; +3A merged-tree drift — batch-10 recorded the same 2477).
  - `DuplicatedTestHelperDrift`: 14T/197A OK GREEN — the whole point of 18a64b8f5.
  - Wide `Server|Hooks` band: 2244T/12767A/3F — AttachPagingTest x2 + WorkspaceHostTest:119.
    PROVEN FOREIGN-STANDING: file-revert probe (every pick-touched candy-code file back to
    965be930f) reproduces the identical 3F; lane REPORT + C-WEB-4.f REVIEW-1 + C-SEC-4.b
    WELD-FINDING records show the same 3F at their bases (batch-8-era carry). Not fixed here.
- DUR mint: scripts/parallel-tests-durations.tsv += candy-code/tests/Server/HookWebhooksSignatureTest.php
  2.90/10 (calibrated against ConformanceTest 2.965/22 and HookWebhooksTest 5.749-era shape);
  monotonicity violations hold at 4.
- Figure carry: +18T suite-figure.json (suite-figure NOT re-pinned here — §7 closeout duty).
- §4 mark: `✅ d3d4b54f4` with dated weld-note. Lane review files committed with closeout.

---- HISTORICAL — batch-10 STOP record (superseded by the weld above; kept verbatim) ----

# WELD — C-AGT-5.d (batch-10, 2026-10-28) — STOPPED, RESET OUT OF MASTER, LEFT IN FLIGHT

- Picks tried: 44fae6291 (aa0dbadfc) + 4bbcc0f6e (3247be1fe) — applied cleanly, then REVERTED
  by rebase --onto (master never carries them; hashes are reflog-only).
- Reason: merged-tree census battery reddened a NEW (non-standing) guard:
  DuplicatedTestHelperDriftTest::testNoDuplicatedConstIsUnrecordedInEitherPolity —
  `private const BODY` declared byte-different at tests/Automation/WebhookSignerTest.php:23
  (pre-existing since C-AGT-5.e 23e4dae80) and tests/Server/HookWebhooksSignatureTest.php:34
  (minted by 44fae6291). The lane's gate list (HookWebhooks|ServerConfigWebhooks|
  ServerProtocolDocumentationDrift 69T/2474A/2D — which did pass, +3A merged drift 2477A)
  never ran the guard; r2 missed it. Post-reset battery re-run: 206T/2F standing-only, green
  restored.
- Fixer ruling needed (either side): rename the new file's BODY (e.g. PUSH_BODY), or add a
  one-line adjudication row to the guard's polity map naming the pair — house law: the guard
  asks for a deliberate decision, not abstention.
- NOT welded: no §4 mark, no DUR row for HookWebhooksSignatureTest (10T), run-state inflight
  KEYS C-AGT-5.d, worktree cc-cmp-C-AGT-5.d + branch cc/C-AGT-5.d preserved.
- Lane review files (BRIEF/REPORT/REVIEW-2) intentionally left UNTRACKED — the re-weld picks
  carry REPORT (4bbcc0f6e tracks it); welding them now would re-create the overwrite abort.
- Disk REPORT.md backup: /tmp/opencode/cc-compare/b10-disk-backup/AGT-5.d-REPORT.md.disk
  (byte-identical to the 4bbcc0f6e version).
