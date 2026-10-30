# C-WEB-2.a — `/btw` over `command.exec` while a turn runs (plan_comparison W30)

VERDICT: DONE. Commit 48ee2233a on cc/C-WEB-2.a (base master 671105299). Authors %ae/%ce detain 1/1, zero trailers.

## What shipped
- `candy-code/src/Host/SessionHost.php` — `runCommand()` busy gate now consults
  `TurnController::midTurnRoute($text, isWorkflowControl($text), SubmitOptions::new())`
  and admits only ROUTE_WORKFLOW_CONTROL / ROUTE_SIDE_QUESTION; every other busy
  verdict (QUIT, REFUSE_COMMAND, QUEUE, STEER, INTERRUPT) keeps the old
  hostCommandNotice refusal. One decision table, two doors — no CommandSpec
  field, no registry edit. Docblock truth-updated; comment notes the default
  SubmitOptions cannot change a '/'-prefixed verdict (table refuses before any
  option read).
- `candy-code/docs/SERVER.md` — `command.exec` prose paragraph now reads
  "`/workflow pause|status` inside its own run and `/btw` excepted". Prose sits
  outside the generated protocol/events regions; ServerProtocolDocumentationDriftTest
  (6T/59A) green in-step — no roster flip owed.
- `candy-code/tests/Host/BtwMidTurnTest.php` (NEW) — 2 tests/19A:
  1. exec-door twin of the keyboard-door /btw test: busy submit → runCommand('btw',…)
     not refused, turn still held; UI-only question row lands; side call carries
     BtwHostCommand::PROMPT on the title backend; async answer drains; turn then
     settles — question row, answer row and turn reply all survive settle AND the
     saved transcript (design risk pinned).
  2. runCommand('clear') while busy still refused with the in-flight notice,
     history unwritten, turn still held.

## Gates (all cwd=candy-code, -c phpunit.xml, plain pipe, env -u LINES -u COLUMNS)
- Regression red BEFORE fix: exactly 1F = the exec-door admission assert (refusal pin already green pre-fix, behavior-preserving proof for other commands).
- BtwMidTurnTest: OK (2 tests, 19 assertions).
- tests/Host full dir: OK (666 tests, 4922 assertions) — includes WebTwoHostCommandsTest, SessionHostCommandsTest, TurnControllerTest.
- CommandMethodsMcpPromptsTest + AttachRemoteCommandsTest + ConformanceTest + ServedProjectCommandTrustTest: OK (38/256).
- ServerProtocolDocumentationDrift --filter: OK 6T/59A.
- Guard filter GlobDialect|DuplicatedTestHelperDrift|DocFigureProseDrift|ReadmeRosterDrift|CommandDocsDrift: OK 135T/4836A (2 PHPUnit deprecations pre-existing, identical count at base).
- Mutation M2 (gate neutered `if (false)`): exactly the refusal pin red at :123; cp+md5 restore verified (e5ee7a84…).

## Handing the welder
- New test file `candy-code/tests/Host/BtwMidTurnTest.php` → needs the durations.tsv row + suite-figure re-pin (§7 duty; untouched here).
- NO protocol trio obligation: command.exec schema/summaries unchanged; grep of docs/protocol/*.json + candy-code-web/src/generated.ts for the refusal prose = zero hits. NO dist rebuild: zero src-web change (web already calls command.exec; this opened the server door).

## Deviations
- None from the design block. Refusal for `/exit`/`/quit` while busy stays refused through this door (ROUTE_QUIT not admitted) — identical to old behavior, deliberate.
