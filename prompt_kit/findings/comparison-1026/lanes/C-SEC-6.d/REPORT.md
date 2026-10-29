# C-SEC-6.d — permission.audit method + AuditLogReader + RulesPanel view

VERDICT: COMPLETE. Commit `ae2da2543` on `cc/C-SEC-6.d` (base e91eb1d47), 8 files, +1015 lines, author/committer detain verified separately, zero Co-Authored trailers. Never pushed.

## Landed
- **`candy-code/src/Hooks/BuiltIn/AuditLogReader.php`** (new) — bounded tail of `AuditHook::defaultLogFile()`. Reads current file then the one `.1` rotation generation, never more (per-file line slice + outer `array_slice(-limit)`); window-walk backwards from EOF in 64 KiB spans until a span starts just after a newline (Serve::tail seek discipline — a long-record file reads whole by design, documented). Parses the three shapes (`=> `, `=! DENY <kind>: `, `=! WITHHELD ["<hook>"]: `) into `{time,session,tool,verdict,kind,reason}`; verdict roster `allow|deny|withheld` = the result schema's enum (pinned equality). Cap 200 rows (DEFAULT 50); limit clamped [1,200] twice (Params bounds refuse out-of-range on the wire BEFORE any read; reader clamps belt-and-braces). Torn last line always dropped; missing file → empty + `unreadable:false`; refused read → empty + `unreadable:true` (never throws). First-marker split documents the input-ambiguity law. No new proc_open.
- **`permission.audit`** (PermissionMethods + MethodSchemas) — `Scope::Approve`, sideEffects false. Chosen deliberately: the log spans every session and project root, so it is read only by the policy voice (tighter than the gap's suggested Read; the reasoning is in the class docblock). Params `{sessionId?, limit<=200}`; result `{rows(maxItems 200), unreadable}`.
- **`RulesPanel.vue`** — 'Recent decisions' section (rows newest-last with verdict-colored action, kind, reason), `auditRows=null` renders "This server does not report audited decisions." for older servers (the audit read never sinks the policy view), and the always-visible note: the trail lives in a per-user directory, a serve under a different HOME/TMPDIR reads a different — usually empty — one. Shares the generation latch; reload re-reads both.
- **`ReadPathCensusTest`** — `Hooks/BuiltIn/AuditLogReader.php|fopen` SELF_LOCATED row landed in-step.

## Root-caused defect found by the e2e pass
PHP caches `filesize()` per path: the log is appended by the same long-lived server process that tails it, so a frozen stat made every second read stale (4 written lines → 3 rows, reproduced). Fixed with `clearstatcache(true, $path)` + a regression pin (`testALineWrittenAfterAnEarlierReadStillShowsInTheNextOne`).

## Scope-refusal pinning law (disclosed)
`Dispatcher` hands every live connection `Scope::owner()`, so a wire-level Approve refusal is unreachable in this build (no existing test asserts Scope::Approve anywhere — respond/forget ride the same registry pin). The method test pins the registry shape via `Dispatcher::methods()` (house idiom, ShellsMethodsTest) plus the limit-boundary refusals and result-schema validation.

## Gates (worktree, plain pipe, env -u LINES -u COLUMNS)
- `tests/Hooks/AuditLogReaderTest.php tests/Protocol/PermissionAuditMethodTest.php` → OK **21 tests / 89 assertions**.
- Protocol band `tests/Protocol` + `ServerProtocolDocumentationDriftTest` + `ReadPathCensusTest` + `AuditHookTest` → OK 157T/1338A and 57T/816A (with trio regenerated).
- vitest `--no-file-parallelism` `rulesPanelAudit.spec.ts` (+`rulesPanelSaved`): 9/9; `dormantRoster` + `panels` 14/14.
- `vue-tsc --build --force`: 20 errors == 20 baseline errors, messages identical except one pre-existing `dormantRoster.spec.ts` error's line position (56→59, baseline cut on an older tree; file untouched by this lane). ZERO new errors (`/tmp/opencode/cc-cmp-C-SEC-6.d/vue-tsc-tip.txt`).

## Mutations (cp+md5 restored, src md5 df569c82… verified after each)
- cap/outer-slice neuter → 1F (rotation-overshoot pin: 210≠200) ✓
- `array_pop` removal → 1F (torn-after-marker pin) ✓
- session-filter neuter → 2F ✓; clearstatcache removal → 3F ✓

## Protocol trio (restored pre-commit — welder re-derives on merged tree)
`php candy-code/scripts/gen-protocol-schema.php --write` + web `npm run gen:protocol`: schema.json **+76**, SERVER.md **+1**, generated.ts **+20** (97 insertions, 0 deletions); `--check` reports up-to-date with them. Snapshots: `/tmp/opencode/cc-cmp-C-SEC-6.d/trio-diff{stat.txt,.patch}`.

## New test files (gate owes durations.tsv rows)
`candy-code/tests/Hooks/AuditLogReaderTest.php` (15T), `candy-code/tests/Protocol/PermissionAuditMethodTest.php` (6T); web spec `rulesPanelAudit.spec.ts` (5 tests). Suite-figure/README re-pin owed by the welder (+21T/+~89A live enum).

## Risks / residuals
- different-HOME/TMPDIR serve reads an empty trail — stated in the panel note (per brief).
- first-marker split can misread a tool input literally containing ' => ' (documented in the reader; input is display metadata only).
- live Approve-token refusal needs a lesser-scope client to exist first (one sentence here; no code owed).
