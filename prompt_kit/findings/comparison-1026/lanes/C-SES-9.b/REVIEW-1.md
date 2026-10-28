# REVIEW-1 — C-SES-9.b "Serve /status prints live spend and cap"

Worktree /home/sites/cc-cmp-C-SES-9.b · branch cc/C-SES-9.b · tip b187b2e6f off master b985fbe51. Tree clean.

VERDICT: FINDINGS (0 CRITICAL / 0 MAJOR / 1 step-local MINOR / 1 NIT)

## Verified clean

- **Diff shape matches claims exactly**: 4 files (CommandContext +9, StatusHostCommand +22/-7, SessionHost +2, WebTwoHostCommandsTest +43). No forbidden files — zero suite-figure.json / durations.tsv / dist / protocol schema / SERVER.md / generated.ts touches; no new *Test.php file (method added to existing file → no durations row owed). No wire-shape change: /status stays CommandResult::reply human text.
- **Author law**: `%ae`=detain, `%ce`=detain (separate greps 1/1), zero Co-Authored trailers.
- **Ctor-param safety**: CommandContext ctor is private, class final, single `new self(` (CommandContext.php:115) inside `new()` — no with*/mutate clone path that could drop the new fields (E736-family state-drop structurally impossible). All 41 `CommandContext::new(` call sites (src/Chat.php ×3, SessionHost, 15 test files) use named args or empty; appended defaulted named params are byte-safe everywhere. Chat's own commandContext() (Chat.php:12255-12282 region) does NOT set the pair → TUI path keeps the constructor pair exclusively (Chat.php:12290-12293 `new StatusHostCommand($this->tokenTracker, $this->maxCostUsd)`), behavior byte-identical.
- **Region discipline**: SessionHost.php single hunk is inside `commandContext()` (CommandContext::new( starts :1911; the +2 lines ride its tail, :1930-1931) — declared region. No touch of the admitPrompt region C-MCP-2.a is working. CommandContext edits = properties/@param/new() per BRIEF "(new(), properties)". StatusHostCommand edits = facts() + class docblock (deviation 1 disclosed, per §1.8 doc-code same-commit law).
- **≥$ lower-bound law honored**: StatusHostCommand.php:81-83 routes through the unchanged `SpendLedger::statusLine` whose `boundAmount()` (SpendLedger.php:88) spells `'≥$'.sprintf('%.4f')` for unpriced usage; the lane adds no money formatting of its own. Test asserts `'≥$'` present on the unpriced seed (WebTwoHostCommandsTest.php new method) — live-green, so the lower bound genuinely rides the serve row.
- **Regression pin fails pre-fix**: cp harness impossible here (reviewer permission map denies cp/mkdir/redirect — `mkdir -p /tmp/...` and `cp` both `permission.rejected`), so re-verified mechanically, airtight: serve builds the command `new $class()` argument-free (BuiltInCommand.php:91, class-string from builtin-commands/1340-status.php:19 `withHostCommand(StatusHostCommand::class)`); the diff's removed lines show pre-fix `spend` reads ONLY `$this->tracker === null` → `Lang::t('cmd.status.spend.untracked')` = 'Live spend: not measured — …' (lang/en.php:1498); the test's first assertion `assertStringNotContainsString('not measured', $row)` therefore fails red pre-fix as a content assertion (the test references no new property, so pre-fix it compiles and fails, never fatals). Deterministic.
- **Non-vacuity**: removing `?? $context->spendTracker` restores the pre-fix degrade → new test red (above). Neutering the cap fallback (`$this->cap` alone) renders 'no cap' (SpendLedger.php:216-218) → `assertStringContainsString('cap $5.0000', $row)` red. Header walk (`'Spend:'` = lang/en.php:1497, value row next, StatusCommand.php:124-125) matches composition.
- **Gates (plain pipe, env -u LINES -u COLUMNS, cwd worktree candy-code)**:
  - `--filter 'WebTwoHostCommandsTest'` → `Tests: 22, Assertions: 76` OK (2 class-ambient deprecations).
  - `--filter 'StatusCommandTest|ContextCommandTest|ChatStatusTest'` → `Tests: 25, Assertions: 162` OK.
  - `--filter 'SessionHostLoopFireTest'` → `Tests: 5, Assertions: 26` OK.
  - 2F ProjectTierRefusalInventoryTest disclosure accepted as base-inherited per orchestrator pre-verification (out of lane scope).

## Findings

### MINOR (step-local) — falsified docblock sentence in Chat.php, undisclosed
`candy-code/src/Chat.php:12283-12284` — "the tracker and cap are the two facts CommandContext cannot carry — they are per-Chat state, which is exactly why the TUI hands them in while any other host lets the durable ledger answer instead." This step makes CommandContext carry them and SessionHost fill them, so the sentence is false at this tip (exactly the doc-truth class the StatusHostCommand docblock was flipped for). Chat.php is outside the lane's owned files, so the fix is a collected doc-row: reword to e.g. "the TUI hands in its per-Chat pair; serve's no-argument build rides the context pair SessionHost fills." REPORT.md discloses deviation 1 (StatusHostCommand docblock) but not this one — the same §1.8 law applies one file over. (Fix-now candidate for the welder: one-sentence replacement.)

### NIT — constructor-preference order unpinned
`candy-code/src/Host/Commands/StatusHostCommand.php:64-65` — `$this->tracker ?? $context->spendTracker` asserts "the TUI's constructor pair wins" (comment :62-63), but no current config has both non-null (Chat never fills the context pair; serve never fills the ctor), so a swapped-`??` mutation is suite-invisible today. Acceptable (ordering is defense for a future both-set driver); a tiny unit pin could land with the fix-wave if the welder is already in the file.

## Weld note
+1T/+7A against the pinned candy-code suite figure → ReadmeSuiteFigureDrift staleness pair expected at battery; re-pin is the standing closeout duty, not a lane defect.
