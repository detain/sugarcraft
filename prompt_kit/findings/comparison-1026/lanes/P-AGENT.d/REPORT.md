# P-AGENT.d — lane report

VERDICT: DONE. Commit 0504a19a6 on cc/P-AGENT.d (base e36d86d84, not rebased, never pushed).

Files (exactly the brief's set):
- modify agents.ts: catalogue colour map (agents.list, loaded once per store), per-run hex index from seeds, colourFor(); message(sessionId, id, text, mode?) rides `mode` only when set; PendingMessage.mode.
- modify SubAgentTree.vue: colour dot per run — seed hex → catalogue-by-name → status-colour CSS fallback.
- modify AgentView.vue: `⚡ interrupt` toggle (aria-pressed) + Ctrl/⌘+Enter one-shot; pending rows badge `⚡ interrupt` / `⧗ waiting for its next step` (↻ wording kept for resuming).
- create RunningAgentsLine.vue; SessionView.vue #after hunk: one import + one slot line.

Gates (cwd worktree candy-code-web, serial):
- vitest agentsParity: 3 passed. Mutation (mode stripped from wire) → exactly 1 red; restore md5-clean.
- vitest agents|panels|stores|tree.spec: 213 passed; src-web/views (sessionView): 2 passed.
- vue-tsc -b --force: ONLY the 6 foreign TS2554 (reducer.spec×4, statusBar.spec×2).

NEW spec file for the vitest roster: candy-code-web/src-web/components/agents/__tests__/agentsParity.spec.ts.

Deviations: none material. jsdom keeps inline hex (spec asserts `#ff8800` not rgb()). No dist commit (welder rebuilds). suite-figure.json/durations.tsv untouched. Plan file untracked — not staged.

## Round-2 review fixes (2026-10-27, commit b98125b2b)

- [MAJOR] AgentView.vue send(): disarm is now `if (interrupt) interruptMode.value = false` after the send rides — a toggle-armed send is one-shot; Ctrl+Enter stays a one-shot (never arms, clears a stale arm). agentsParity.spec.ts rewritten to assert, right after the first toggle-armed send (post answer+flush), aria-pressed 'false' AND a plain follow-up send carries no mode. Non-vacuity: the new spec reddens on the unfixed src (`expected 'true' to be 'false'`, /tmp/opencode/cc-cmp-P-AGENT.d-r2/red-unfixed.log); green after the fix.
- [MINOR] agents.ts catalogue docblock: hex is "the same hex the TUI strip paints under the default theme" (server resolves against Theme::default(); a themed TUI may paint word-colours differently).
- [MINOR] AgentView.vue docblock: dropped false "as the TUI does" — the web adds ⚡ to the TUI's ⧗ row-state vocabulary (⧗/↻/↩).
- [NIT] RunningAgentsLine header queued-as-running + static ⠋: left as recorded.
- Gate: npx vitest run --no-file-parallelism agentsParity agents panels stores tree sessionView -> 27 files / 215 tests, all green. No dist. Author %ae/%ce detain@interserver.net, zero trailers.
