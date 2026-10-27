### C-AGT-2.b — Review turns run read-only (plan clamp)
- item: C-AGT-2 (sources: GIT-1-P2)
- kind: fix · MINOR · effort M
- approach: Root cause: the "report, do not edit" mandate is prompt prose. The review turn runs under whatever mode the session holds (`src/Chat.php:11798` plain `submit()`), and the only test pins docblock text (`tests/Chat/ReviewCommandTest.php:130-131`).
  - TUI: `reviewTurn()` swaps the gate to `PermissionMode::Plan` for that one turn. Reuse the gate-swap body of `togglePermissionMode()` (:17390-17428), extracted into a private helper. A `reviewModeRestore` field puts the prior mode back on every settle path (`HandlesTurnCompletion::handleTurnCompletionMessages`), including cancel and error. Announce the clamp in a notice row. A session already in plan or dont-ask is left as it is.
  - Serve: add a turn-scoped `SubmitOptions::withModeCeiling(PermissionMode)` that `SessionHost` honours for that turn's gate (`sessionGate()`/`dispatch`). C-WEB-2's `ReviewHostCommand` then has a door to use.
- files.create: none
- files.modify: `candy-code/src/Chat.php` (`reviewTurn`, `togglePermissionMode` extraction, state field), `candy-code/src/Chat/Handlers/HandlesTurnCompletion.php` (`handleTurnCompletionMessages`: restore), `candy-code/src/Host/SubmitOptions.php` (`withModeCeiling`), `candy-code/src/Host/SessionHost.php` (`dispatch`/`sessionGate` per-turn ceiling), `candy-code/src/Commands/ReviewCommand.php` (docblock: the clamp is now the action-side answer), `candy-code/lang/en.php` (`cmd.review.clamped`)
- tests: modify `candy-code/tests/Chat/ReviewCommandTest.php` (replace the :130-131 prose pin: under a bypass-mode Chat, a scripted Write/Bash call during `/review` is refused; the next ordinary turn runs in bypass again; a cancelled review restores the mode); create `candy-code/tests/Host/SessionHostModeCeilingTest.php` (a ceiling'd submit refuses a write; the next submit does not)
- docs/guards: none (no key, setting or command roster moves)
- serialized: [chat:reviewTurn, togglePermissionMode, applyCommandResult (none — the restore lives in the settle trait),ctor] [hot:candy-code/src/Chat/Handlers/HandlesTurnCompletion.php] [hot:candy-code/src/Host/SessionHost.php] [lang: `cmd.review.*`]
- deps: C-AGT-2.a
- phase: normal
- ruling: none. It enforces the shipped "Report, do not edit" law. The owner may veto if `/review` should keep write power.
- risks: the status-bar mode reads "plan" during a review turn. That is intended and visible, but the restore must cover every settle path, or the session sticks in plan.

