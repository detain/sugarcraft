### P-AGENT.c — Protocol: `agents.list` colour + `agents.message` mode
- item: P-AGENT-ATTACH (sources: k1-dorm#3, CLAW-new#7)
- kind: feature · effort S
- approach:
  - `agents.list` items gain an optional `color` (the preset's, resolved through `AgentColor` to a hex or omitted).
  - `agents.message` gains an optional `mode: steer|interrupt` (default steer). `AgentsMethods` passes it to `AgentMessage::fromUser($text, MessageMode)`.
  - Schemas at `src/Protocol/Schema/MethodSchemas.php:426-465`; regenerate the chain.
- files.create: none
- files.modify: `candy-code/src/Protocol/Methods/AgentsMethods.php` (list, message), `candy-code/src/Protocol/Schema/MethodSchemas.php`, `candy-code/docs/protocol/candy-code.v1.schema.json` (gen), `candy-code/docs/SERVER.md` (gen), `candy-code-web/src-web/protocol/generated.ts` (gen)
- tests: create `candy-code/tests/Server/AgentsColorAndInterruptTest.php` (colour present or omitted; an interrupt-mode mailbox line; a bad mode is refused by the schema)
- docs/guards: the gen `--check`s; `tests/Config/ServerProtocolDocumentationDriftTest.php`
- serialized: [protocol] [docs:SERVER.md]
- deps: P-AGENT.a
- phase: normal
- ruling: none
- risks: must not share a wave with P-CTX.f or any other protocol lane.

