### P-AGENT.d — Web agents parity: colours, interrupt send, running-agents line (CLAW-new#7)
- item: P-AGENT-ATTACH (sources: CLAW-new#7, k1-dorm#3)
- kind: feature · effort M
- approach:
  - `agents.ts` keeps the catalogue's colours by name and `message(sessionId, id, text, mode)`.
  - `SubAgentTree` paints a colour dot per run.
  - `AgentView` gets an "interrupt" send (a toggle, plus Ctrl+Enter) with the same `⚡`/`⧗` row states as the TUI.
  - A new `RunningAgentsLine` in SessionView's transcript `#after` slot: `⠋ 2 agents running` with per-child activity rows. Each row opens that run (`panels.openAgent`). This is OpenClaw's working-line countdown and launch rows linking in.
- files.create: `candy-code-web/src-web/components/agents/RunningAgentsLine.vue`
- files.modify: `candy-code-web/src-web/stores/agents/agents.ts`, `candy-code-web/src-web/components/agents/SubAgentTree.vue`, `candy-code-web/src-web/components/agents/AgentView.vue`, `candy-code-web/src-web/views/SessionView.vue` (`#after` slot)
- tests: create `candy-code-web/src-web/components/agents/__tests__/agentsParity.spec.ts` (dot colour; interrupt mode sent; the line counts running runs and opens one)
- docs/guards: web unit tests
- serialized: [hot:candy-code-web/src-web/views/SessionView.vue]
- deps: P-AGENT.c
- phase: normal
- ruling: none
- risks: low. Colours come from the server already contrast-checked; the web still falls back to the status colour.

