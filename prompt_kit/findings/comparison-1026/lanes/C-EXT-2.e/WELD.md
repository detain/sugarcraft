# C-EXT-2.e — WELD record (W13)

Welder: cc-cmp welder · 2026-10-28 · master pre-pick ee69f341c.

## Pick
- `e0fbbff81` → **f63217396** on master. Cherry-pick CLEAN — zero conflicts.
  The briefed Chat.php region conflict ([chat:submit custom-command branch])
  did not materialize (base 63f2de5d9 → master touched no colliding hunk).
- 13 files, +1009/−79 — matches the lane report exactly. Protocol trio absent
  from the diff (docs/protocol/candy-code.v1.schema.json, docs/SERVER.md,
  candy-code-web/src-web/protocol/generated.ts) → NO regen rider for this lane.

## Merged gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS, PtyRunToolTest never in a filter)
- `CustomCommandModelOverride|TurnControllerCommandRouting` → 19T/51A — EXPECTED ~19T/51A EXACT.
- `CommandLoader|FrontmatterKeyAudit|InertFrontmatter|BootstrapLaunchNotice` → 99T/949A EXACT.
- `TurnController|SessionHostCommands` → 60T/232A EXACT.
- `CustomCommand|ChatSubmit|MidTurnSpendCap` → 103T/450A EXACT.
- `SessionHostTest|TurnRunner|InertFrontmatter|FrontmatterKeyAuditTest` → 77T/336A EXACT.
- Broad `Chat|SessionHost|TurnController` → 1973T/14216A/**8F** — the 8 are exactly the
  base-inherited families the lane proved red at its own base (FIX-1.md VERIFICATION pass,
  1968T/14167A/8F same families): AutomaticCompactionModelSummaryTest breaker 1F,
  ChatHostLangCatalogueTest 1F, ChatTest title-suggestion AsyncCmd-shape 5F, DreamPassTest 1F.
  Zero lane-added failures. Standing-red list per weld brief; NOT cured, NOT attributed.

## Durations
- Separate `scripts:` commit a7c2a81a2: 2 rows (junit per-file sums at the merged gate run):
  - `candy-code/tests/Chat/CustomCommandModelOverrideTest.php 0.021 22` (5T — recounted, brief's ~5T confirmed)
  - `candy-code/tests/Host/TurnControllerCommandRoutingTest.php 0.002 29` (14T)
- tsv set count 1498 → 1500 (+2 = the 2 new candy-code test files, set-equality PASS).

## Optional weld cures — NOT applied (recorded instead)
The r2 CLEAN note (run-state) lists two cosmetic weld-time cures: a `Chat.php:615`-era
docblock cite refresh (13→16) and a `:10049`-era blank line at the new param splice.
Neither is grep-identifiable as an unpinned defect on the merged tree:
- Chat.php:615 (merged tree) sits inside the OSC52_MAX_CHARS docblock — no frontmatter/key
  count to refresh; no `13`→`16` cite candidate found anywhere near the lane's Chat.php hunks
  (the lane's own 13→16 docbook moves live in FrontmatterKeyAudit.php, already landed).
- The `:10047` commandModelNotice block is blank-line-separated from the following
  TURN-LIFECYCLE comment in the merged tree already (blank at :10053).
REVIEW-2.md is not on disk in the lane dir (verdict recorded only in run-state), so the
exact nit anchors cannot be re-derived. Per the weld brief these cures are conditional on
grep proof; proof negative → no speculative edit. Carried back to the lead as this record.

## Absorption + cleanup
- `git cherry master cc/C-EXT-2.e` → see weld commit report ('-' expected).
- Worktree /home/sites/cc-cmp-C-EXT-2.e removed, branch cc/C-EXT-2.e deleted after proof.
