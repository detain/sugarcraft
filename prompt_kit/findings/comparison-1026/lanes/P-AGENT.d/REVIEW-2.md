# P-AGENT.d — REVIEW-2 (fix commit b98125b2b, parent 0504a19a6, base e36d86d84)

VERDICT: CLEAN

- Scope: the fix commit names exactly the three files the three findings need (AgentView.vue,
  agentsParity.spec.ts, agents.ts) — no stray edits. Lane-wide the range e36d86d84..b98125b2b is
  the design block's set only (agents.ts, SubAgentTree.vue, AgentView.vue, SessionView.vue +
  created RunningAgentsLine.vue + created agentsParity.spec.ts). No weld-only file (dist/,
  suite-figure.json, durations.tsv) is committed.
- MAJOR (sticky toggle) CURED: AgentView.vue:78 gates on `interrupt` — the boolean that actually
  rode the wire — so any send that used the armed toggle releases it, and the next plain send
  computes `interrupt === false` at :73 and posts mode-less. Spec legs pin it: :79 asserts
  aria-pressed false right after the armed send; :88 asserts the following plain send's `mode` is
  undefined. Under the r1 form (`if (mode === 'interrupt')`) both redden — the Send-button path
  passes `mode` undefined, so the arm would persist and :88 would ride interrupt.
  (Mutation-reasoned, not executed: edit/write/shell all refuse this file's path.)
- MINOR (agents.ts:65-68 colour parity) CURED honestly: the docblock now says the hex is what the
  TUI paints "under the default theme (the server resolves against Theme::default(), so a themed
  TUI may paint word-colours differently)". Disk-true: AgentsMethods.php:103/118 is the only
  agents.list colour site and uses `Theme::default()`; the TUI row/chip sites
  (WindowStrip.php:359, SessionsPane.php:189, AgentActivityLine.php:176) take the renderer's
  active Theme.
- MINOR (AgentView.vue:18-22 "as the TUI does") CURED: rewritten as the web adding `⚡` to the
  TUI's row-state vocabulary (`⧗` queued, `↻` running, `↩` replied) — matches
  AgentMessageSentMsg.php:48-50, and candy-code src/ + lang/ contain zero `⚡`.
- Gates run at b98125b2b: `npx vitest run --no-file-parallelism agentsParity agents panels stores
  tree sessionView` → Test Files 27 passed (27), Tests 215 passed (215), Duration 16.44s.
  `npx vue-tsc -b --force` → exactly the 6 foreign TS2554s (statusBar.spec.ts:200,209;
  reducer.spec.ts:307,312,317,320), zero in lane files; those two specs are untouched by the range.
- Author law: `%ae` and `%ce` are detain@interserver.net on both commits (separate greps), zero
  Co-Authored-By trailers; `git status --porcelain` empty.
- NIT (step-local, non-blocking): AgentView.vue:77's comment claims an explicit Ctrl+Enter send
  "clears a stale arm too", but nothing pins it — the spec's Ctrl+Enter leg (:92-98) runs with the
  toggle already disarmed, so a `mode === undefined`-only reset would survive. Micro for a later
  round: arm the toggle, then Ctrl+Enter, assert aria-pressed false. r1's queued-count NIT stays
  open as r1 left it.
