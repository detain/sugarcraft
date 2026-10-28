# C-EXT-2.e — Command frontmatter `model`/`agent`/`subtask`

- area: ext · kind: dormant-wire · effort M · phase: normal · item: C-EXT-2 (sources: GAP-CMD-04, k2-dorm inert command keys)
- deps: C-EXT-2.b (FrontmatterKeyAudit and InertFrontmatter guard ordering)
- serialized: [chat:submit custom-command branch] [docs:COMMANDS.md] [lang:host.turn.] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Host/TurnController.php] [hot:candy-code/src/Support/FrontmatterKeyAudit.php]
- ruling: none

## Files — create
none

## Files — modify
`candy-code/src/Commands/CommandSpec.php` (`agent`), `candy-code/src/Host/TurnController.php` (`expandCustomCommand`), `candy-code/src/Chat.php` (submit custom-command branch), `candy-code/src/Host/SessionHost.php` (`admitExpanded`), `candy-code/src/Support/FrontmatterKeyAudit.php` (COMMAND rows), `candy-code/lang/en.php` (`host.turn.*`), `candy-code/docs/COMMANDS.md` (frontmatter rows :120-121 and the KNOWN-not-INERT paragraph)

## Tests
create `candy-code/tests/Host/TurnControllerCommandRoutingTest.php` (directive shape per key), `candy-code/tests/Chat/CustomCommandModelOverrideTest.php` (one turn only; restored after). Modify `candy-code/tests/Host/SessionHostCommandsTest.php`, `candy-code/tests/Commands/CommandLoaderTest.php` (agent parse), `candy-code/tests/Support/FrontmatterKeyAuditTest.php`, `candy-code/tests/Config/InertFrontmatterDocumentationDriftTest.php`.

## Docs/guards
the INERT ↔ COMMANDS.md guard.

## Approach

- **What's inert.** `CommandSpec.php:205-210` parses `model` and `subtask`, both INERT (`FrontmatterKeyAudit.php:131-134`). `agent` is KNOWN but not parsed. `TurnController::expandCustomCommand()` (:448) expands into the current conversation on the current model.
- **Fix.**
- CommandSpec gains `agent`.
- When `subtask: true` or `agent:` is set, `expandCustomCommand()` wraps the expansion in a fenced delegation directive: Task, with `agent` (named, else `coder`) and the command `model`. That is one implementation for the TUI, serve and `-p`.
- An inline `model:` without subtask runs that ONE turn on `backend->withModel()`. A model the provider cannot serve falls back to the session model with a notice, the Task rule. This happens in Chat's custom-command submit branch (Chat.php ~9520-9560) and in `SessionHost::admitExpanded()` (:942).
- The COMMAND `model` and `subtask` rows leave INERT.

## Risks
a subtask relies on the model calling Task when told to. The directive is pinned, but compliance is not guaranteed.

Brief generated from schedule.json (tick 69).