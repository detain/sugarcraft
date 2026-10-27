# P-AGENT.c — CLOSED 21c8fd6c5 (cc/P-AGENT.c, base 86f43fae2, not pushed)

Shipped: `agents.list` items carry optional `color` (preset vocabulary/literal hex
resolved through AgentColor against Theme::default(), `#rrggbb`; omitted when no
legible colour). `agents.message` gains optional `mode: steer|interrupt` (default
steer) — public const `AgentsMethods::MESSAGE_MODES` drives the schema enum (the
CONTROL_VERBS precedent), `MessageMode::from(...)` rides `AgentMessage::fromUser`.
MethodSchemas region 426–465 only. Generated trio + web generated.ts regenerated
locally, verified +12 schema lines/+4 generated.ts lines, SERVER.md byte-stable
(optional params are not tabled), then RESTORED pre-commit per §1.8 (welder
regenerates; dist untouched, never built).

## Files
- M candy-code/src/Protocol/Methods/AgentsMethods.php
- M candy-code/src/Protocol/Schema/MethodSchemas.php
- M candy-code/tests/Server/Support/ProtocolFixture.php  (deviation: +optional
  `?AgentManager` param — the established supervisor/workflows/memory passthrough
  pattern; WorkspaceContext::new already takes it)
- NEW candy-code/tests/Server/AgentsColorAndInterruptTest.php 4T/18A

## DUR (gate adds to durations.tsv)
- candy-code/tests/Server/AgentsColorAndInterruptTest.php

## Gates (plain pipe, cwd worktree candy-code, -u LINES -u COLUMNS)
- --filter 'AgentsColorAndInterruptTest': 4T/18A green (fails pre-fix by
  construction: no colour key, mode unparsed)
- --filter 'Agents': Tests: 1693, Assertions: 11874, 0F
- --filter 'ProtocolSchemaDrift|ServerProtocolDocumentationDrift' (on generated
  tree): 12T/332A 0F
- five-guard: 133T/15689A, failures = ONLY the staleness pair (figure re-pin is
  gate duty)
- vitest --no-file-parallelism src-web/protocol/__tests__/generated.spec.ts: 3/3
- "PHPUnit Deprecations: 2" is pre-existing family-wide (sibling
  AgentsWorkflowMethodsTest reports the same 2).

## Notes
- Contrast floor: '#3399FF' is NOT legible on the default theme (ratio 3.39 <
  4.5) — fixture literal switched to '#003D99', registered uppercase so the
  lower-case round-trip stays a live discriminator.
- Author law: %ae=%ce=detain@interserver.net (separate greps), zero trailers.
