# C-AGT-2.b — Review turns run read-only (plan clamp)

Commit: `ee187b3fd` on `cc/C-AGT-2.b` (base c2477fe71), 7 files, +665/−30, author/committer detain@interserver.net, zero trailers.

## What landed
- **TUI (`src/Chat.php`)**: `reviewTurn()` steps a gate looser than `plan` down to `plan` for exactly that one turn via new `swapPermissionGate()` (the gate-swap body extracted verbatim from `togglePermissionMode()`, which now delegates — guards unchanged). New `reviewModeRestore` ctor field (carried through `mutate()`'s prop map) + a release chokepoint IN `mutate()`: any mutate carrying `inFlight => false` with an outstanding clamp restores the prior mode — covers answer, refusal, double-Escape cancel (HandlesKeys) and error settles without touching the hot files. Announce row `cmd.review.clamped` rides the transcript. Already-`plan` untouched; `dont-ask` NOT traded (strictness trade). Status bar shows `plan` during review — intended.
- **Serve (`src/Host/`)**: `SubmitOptions::withModeCeiling(?PermissionMode)` (turn-scoped: rides only submits that START a turn — ROUTE_INTERRUPT re-admit carries it; queued release never does, docblock-disclosed). `SessionHost` threads it admitPrompt→fireTurnHooks(forked re-entry included)→dispatch→`backend($ceiling)`→`sessionGate($base,$ceiling)` = `$mode->stricterOf($ceiling)`; proven byte-equivalent for ceiling=null; never invents a gate where none is wired. Public `permissionGate()` stays ceiling-free. Door for C-WEB-2's ReviewHostCommand.
- **`src/Commands/ReviewCommand.php`**: docblock flipped — the clamp is now the action-side answer to Report-do-not-edit (UNTRUSTED BY CONSTRUCTION + docs/CI.md sentences kept). **`lang/en.php`**: `cmd.review.clamped` (candy-code ships en-only lang; no per-locale parity guard exists).

## Tests
- `tests/Chat/ReviewCommandTest.php`: prose pins replaced by behavioral trio (bypass-mode /review: Write REFUSED "Tool error: Hook denied: Permission mode 'plan' does not allow Write" + restore on settle; next ordinary turn runs bypass again; double-Escape cancel restores) + already-plan / dont-ask untouched pins + gateless no-op assert. Structural (starts-with) matching guards against turn-context diff echo-contamination (§1.9 hazard — the refusal needles live in this very file's diff).
- `tests/Host/SessionHostModeCeilingTest.php` (NEW): ceiling'd submit refuses Write + session gate unmoved; next submit runs; Bypass-ceiling never loosens a plan session; gateless session unaffected.
- Red-before-green: base run → `Errors: 3` (withModeCeiling undefined) + `Failures: 2` (clamp absent) via `git stash` of src/lang only; pop restored.
- Gates: `--filter 'ReviewCommandTest'` 14T/89A OK; `--filter 'SessionHostModeCeilingTest'` 3T/14A OK; `--filter 'Review|ModeCeiling|Permission|SessionHost'` **2367T/13558A OK**; five-guard **133T/15654A, Failures: 2 = staleness pair ONLY** (live 26,029 vs pinned 25,537 — fleet-wide, re-pin is gate duty).

## DUR LIST (new test file → durations.tsv row owed by the gate; NOT edited here)
- `candy-code/tests/Host/SessionHostModeCeilingTest.php`

## Deviations
- `src/Chat/Handlers/HandlesTurnCompletion.php` NOT edited (in files.modify): the `mutate()` chokepoint covers its release path and every other one — hot file left untouched (fewer hunks than prescribed, same coverage; pinned by the settle test).
- `admitExpanded` names no ceiling (expansion is the user's own prompt; disclosed in docblock).
- `/review` typed mid-turn rides the queue UNCLAMPED (clamp is between-turns-only, like every other mode surface) — disclosed in reviewTurn docblock.
- Cancel test starts no fork on purpose (child-kill machinery already pinned by ChatCancelKeepsLoopLiveTest; a cancelled promise resolves null).
- suite-figure.json / durations.tsv / dist / generated protocol files untouched.
