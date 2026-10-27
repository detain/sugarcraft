# C-WEB-1.a — session.updated serves the switched model; renames announced

**Lane commit:** `6f07646c8` on `cc/C-WEB-1.a` (base `e4689c28e`), worktree `/home/sites/cc-cmp-C-WEB-1.a`. Author/committer both detain@interserver.net (separate greps), zero Co-Authored-By. Not pushed.

## What landed
- `src/Host/SessionHost.php` — `modelOverride(): ?array` (records `{provider, model}` beside the `SetModel` backend; a provider-only switch records the model the built engine resolved, never a blank) + `onSummaryChanged(?\Closure)` hook, fired ONCE after the command's transcript rows land when effects carried `SetModel` or `RenameSession` (flag set in the arms; `RenameSession` split out of the grouped bare `break`).
- `src/Protocol/ServerContext.php` — `summary()` overlays the open host's `modelOverride()` (non-empty legs; a closed host reverts to the row — the override dies with it, W7a-2 law); `feedFor()` binds the hook to the same server-scope `session.updated` broadcast the `session.rename` RPC rides, on every attach path (recycled hosts rebind); `dropFeed()` clears the hook (hook lives exactly as long as the feed).
- `src/Protocol/EventType.php` — `SESSION_UPDATED` description gains the model arm (description-only, per brief).
- `tests/Protocol/SessionSummaryModelOverlayTest.php` — NEW, 4T/28A: (a) `command.exec /model openai gpt-x` → `summaryOf` names it, exactly ONE `session.updated`, schema-clean payload, row untouched; (b) typed `session.send '/model …'` same + `backend->sent === []` (no turn dispatched); (c) served `/rename` broadcasts the new name; (d) close reverts to row (`p`/`m`, `open false`) + reopen-switch speaks again (rebind proof).
- `tests/Host/WebTwoHostCommandsTest.php` — 2 tests added: newest `/model` replaces the override (no first-choice kept alive); provider-only switch records the engine's resolved model.

## Gates (all targeted, plain pipe, env -u LINES -u COLUMNS, cwd=candy-code)
- `vendor/bin/phpunit tests/Protocol/SessionSummaryModelOverlayTest.php` → `OK (4 tests, 28 assertions)`
- mutation (hook-fire neutered): → `Tests: 4, Failures: 4`, restore md5-verified `010c049e…`
- `tests/Host/WebTwoHostCommandsTest.php tests/Host/ModelHostPersistenceTest.php` → `OK (27 tests, 85 assertions)`
- `tests/Config/ServerProtocolDocumentationDriftTest.php tests/Protocol/ProtocolSchemaDriftTest.php tests/Protocol/EventTypeTest.php` → `OK (15 tests, 455 assertions)` (run against regenerated schema/SERVER.md)
- `tests/Server tests/Protocol` → `Tests: 419, Failures: 2` — both in `AttachPagingTest` (RemoteSessionHost); **proven base-inherited** with file-copy harness (same 2F with base src restored).
- five-guard filter `ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift` → `Tests: 133, Failures: 2` = the expected staleness pair only (pinned 25,537 vs live 25,746 — lane adds +4).
- `php scripts/gen-protocol-schema.php --write` then `--check` → up to date; `npm run gen:protocol` then `--check` (cwd candy-code-web, npm ci was complete) → up to date. **Trio restored before commit per §1.8** — the welder must re-run BOTH generators: the EventType description moves `candy-code.v1.schema.json`, `docs/SERVER.md`, and `candy-code-web/src-web/protocol/generated.ts`.

## Durations duty (gate adds)
- NEW file: `candy-code/tests/Protocol/SessionSummaryModelOverlayTest.php` → needs a row in `scripts/parallel-tests-durations.tsv` (lane forbidden to edit it).
- No `suite-figure.json` touch; figure re-pin belongs to the welder (pair-only red disclosed above).

## Deviations / notes
1. Provider-only `/model <provider>`: effect names no model; recorded the built engine's `model()` instead of `''`, so the summary never blanks what is actually answering (commented in the arm; pinned in WebTwoHostCommandsTest).
2. Hook fires AFTER the command's transcript rows announce (stream order matches transcript), once per command regardless of effect count.
3. `dropFeed` hook-clear is belt-and-identity: a dropped host is usually closing, but the invariant "hook ⇔ feed" keeps a context that stopped following a session from being its annunciator.
4. No child-lifetime, stderr-emitter, or sprintf census sites added; no Lang keys; E646 clean (no timers anywhere in the change).
5. TrustAsks.php untouched (C-SEC-4.b's); shared [protocol] surface here is EventType description-only + generated-trio regen at weld — if the welder runs gen-protocol after welding C-SEC-4.b too, one regen covers both.
