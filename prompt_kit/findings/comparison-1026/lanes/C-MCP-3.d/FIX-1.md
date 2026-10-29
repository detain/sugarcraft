# C-MCP-3.d — FIX-1 (round-1 review fixes)

Branch `cc/C-MCP-3.d` @ /home/sites/cc-cmp-C-MCP-3.d, base `6742a2ad3`, prev tip `9b34f63bd`.
New tips (cat-file = `commit` both): **`925d39ad0`** code + ReadPathCensus rows,
**`e0ff98c4c`** pins. `%ae` = `%ce` = `detain@interserver.net` on both, zero Co-Authored trailers,
working tree `dirty=0`. Range: 6 files, +489/−20.

## CRITICAL (round-1): turn-bound arming forked the WHOLE MCP exchange → CLOSED via remedy (b)

`turnTools()` armed every MCP call, so a backgrounded call's bound `permissionApprover` /
`toolProgressEmitter` closures ran on severed child copies — the ask never reached the human and the
tail wrote to nobody. The reviewer's red witness (`tests/Tools/McpToolProgressTest:361`) is GREEN now:
`--filter McpToolProgress` = **15 tests / 68 assertions, OK**.

Chosen remedy **(b) — never background a call that holds the turn's ask channel or live tail**:
`McpBackgroundCalls::armed()` gained a 4th door `$serverRequestChannelsBound`
(`permissionApprover !== null || toolProgressEmitter !== null`), passed at both call sites
(`McpToolBridge.php:613`, `BuiltIn/McpCallTool.php:195`). Honest new pin
`testACallHoldingTheTurnsChannelsBlocksWhileACallHoldingNeitherHandsOff` — leg 1 approver-bound call
asks inline (1 ask, no child), leg 2 tail-bound call delivers 3 frames in-process, leg 3 CONTROL: an
unbound long call still hands off.

Rejected **(a)** (registry `<job>.ask`/`.answer` side-channel + parent drain): `BRIEF.md` for 3.d does
not mandate backgrounding an ask-bound call ("Fast calls are byte-identical… `-p` never backgrounds";
elicitations are C-MCP-3.b's), and the relay would bolt a second, bidirectional protocol onto a
directory designed as a one-shot result mailbox. **Cost disclosed**: an elicitation-capable or
tail-capable long call keeps blocking inline = pre-feature behavior; "background a call that asks
mid-flight" is an **open seam for §6**, not papered over.

False prose corrected: `docs/MCP.md` no longer claims elicitations/progress ride the child's desk;
`await()`'s docblock relay paragraph replaced with the truth + a dated note that the claim was
measured false at round 1.

Note for the weld ledger: my re-brief described `McpElicitationRelay.php` + a 150-line relay test as
existing on disk. **They never existed here** — `git status --porcelain` at fix time showed exactly the
5 modified files above and no untracked files (pasted in session). Closure is the door, not a relay.

## MAJOR-1 (tautological fast-path pin) — CLOSED

Kernel that was real: the production arming seat (`EngineBackend` `turnTools`, the
`AcceptsToolProgress` arm at `:3503`) had **no pin**, so deleting it left every test green (dead-code
relapse). Partly stale: the findings twin reviewed an older revision — at `9b34f63bd` the hand-off
tests already cross the boundary (`THRESHOLD=0.15` vs a 0.35 s call) and `:251` already asserts
`assertSame(120.0, DEFAULT_AFTER_SECONDS)` + the docs sentence + lang key order (so NIT-4 was already
covered — see M1).
New pins: `testTheTurnBindingIsWhatHandsTheProductFigureToRealCalls` (slices the arming window, both
spellings named, the figure passed, exactly one `withBackgroundAfter(`), `testTheDialDecidesTheBranchASweepAcrossTheBoundaryShows`
(0.05 s threshold → hand-off + later delivery; 5 s threshold → inline bytes),
`testTheHandOffReleasesTheTurnAndNeverSignalsTheChild` (E646 both ways: child alive at hand-off via
`posix_kill($pid,0)` and publishes on its own schedule; structurally, `await()`'s body may contain none
of `posix_kill/pcntl_alarm/pcntl_signal/pcntl_sigprocmask/SIGKILL/SIGTERM/SIGINT/proc_terminate/terminate(`).

## MINOR-2 (capable-paths only) — CLOSED

`armed()` also gained the **turn door** `$turnDriven` (`$heartbeat !== null` — Runtime passes a beat
only while a turn blocks, `Runtime.php:2488` sole `executeWithHeartbeat` caller). Behaviour:
`testAnArmedToolCalledWithoutATurnBeatNeverForks`; door table + structural door-naming pin in
`testArbitrationArmsOnlyOnACapableTurnDrivenCall`; doc sentence landed in `docs/MCP.md` (fork/`pcntl` +
private home + waiting turn, else inline byte-for-byte, Windows included).

## MINOR-3 (`McpHeartbeatProgress`) — PHANTOM, substance pinned instead

`McpHeartbeatProgress` and `progressMessage` exist nowhere in the worktree **or** `origin/master`
(`git grep -l` = 0 hits); the cited `McpClientTest.php:321/332/355/659/660` are resolveEnv/callTool
tests there too. The real sibling shape is `McpToolBridge::progressSink()/progressTail()/progressPacing()`
(bridge spelling already pinned in `McpToolProgressTest`); the **dispatched** spelling's parity was
unpinned → `testTheDispatchedSpellingRendersProgressInTheSiblingShapes`: blank message → pacing
`33.3%`, floor `0.1`, over-budget message clamped to `PROGRESS_TAIL_BUDGET` with `truncated`,
seqs `1,2,3`, `toolCallId`/`McpCallTool::NAME`.

## Declarations (out-of-region hunks, for the weld ledger)

1. `candy-code/src/Backend/TurnInbox.php` +7 docblock lines (landed in the skeleton commits, "Two
   implementations" → "Three"): truthful, but **outside the brief's declared files** — KEPT, because
   reverting would make the file lie again.
2. `candy-code/src/Backend/EngineBackend.php` — the round-1 fix touches the `turnTools` **arming**
   hunk (`:3495-3509`), not only the declared `:5022` composite-inbox region. Reviewer checked the 9
   sibling briefs: only `C-SES-7.f` shares a file and it names `lang/en.php`, so no live collision.
3. Reviewer's out-of-scope NIT (hand-rolled `pcntl_fork` vs the `ForkedChild` test helper) not taken:
   no new `proc_open` anywhere this round, so `check-child-lifetimes` roster stays untouched.

## Gates (cwd=candy-code, `-c phpunit.xml`, `env -u LINES -u COLUMNS`, plain pipe)

| filter | result |
|---|---|
| `McpBackgroundCallTest` | 15T / 123A OK (8 → 15 tests: **+7T** new methods, existing file) |
| `McpBackground\|McpToolProgress` | 32T / 203A OK |
| `TreeWideGuardRoster\|ReadPathCensus\|StderrEmitterCensus` | 131T / 26644A OK |
| `EngineBackend` | 297T / 2051A OK |
| `Mcp` (broad net) | 1513T / 8879A, 1 skipped |

Every run carries 2 foreign `PHPUnit Deprecations` (`AccessorDeprecationTest` private data providers —
pre-existing, unrelated). `ReadmeSuiteFigureDrift` staleness pair red **as expected** (pinned
25,537 vs live 28,054): closeout re-pin carries this lane's **+7T** and the sibling lanes. No
`suite-figure.json`, `durations.tsv`, `dist/`, or protocol-trio edits (forbidden). `ReadPathCensus`
needed 3 new roster rows for the skeleton's registry sinks (`file_get_contents` 1, `fopen` 1, `scandir` 2
— SELF_LOCATED/NAMES_ONLY verdicts) and was RED until they landed; `TurnInbox` needed none.

## Mutation proofs (`/tmp/opencode/cc-cmp-C-MCP-3d-fix1/mutate.py`, filter `McpBackground|McpToolProgress`)

| # | mutation | reds | restore md5 |
|---|---|---|---|
| M1 | `DEFAULT_AFTER_SECONDS 120.0 → 30.0` | 1 — `…FigureAndSentenceAgreeAcrossCodeLangAndDocs` | OK |
| M2 | hand-off arm neutered (`if ($now >= $deadline)` → `if (false)`) | 7 — incl. sweep, no-kill, control leg | OK |
| M3 | **CRITICAL reversion**: channel door deleted | 2 — door table + bound-channel pin | OK |
| M4 | production arming deleted from `turnTools` (`=> $tool,`) | 1 — wiring pin (non-vacuity proven) | OK |
| M5 | opposite choice: nothing ever backgrounds | 8 — incl. the control leg (both directions discriminate) | OK |

## §6 seam for the lead

Backgrounding an MCP call that asks the human mid-flight (relay option (a)) remains unbuilt by
ruling; the feature therefore covers only non-interactive long calls. Needs a product ruling if the
plan wants ask-capable calls backgrounded too.
