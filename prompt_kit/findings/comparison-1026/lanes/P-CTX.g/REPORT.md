# P-CTX.g — web context panel categories + status cache meter + turn-completed reload

DONE. Branch `cc/P-CTX.g` @ worktree `/home/sites/cc-cmp-P-CTX.g`, base `c2477fe71`,
commit `7f85a5ab6` — author/committer Joe Huss <detain@interserver.net> (%ae/%ce
separate greps 1/1), zero Co-Authored-By, never pushed.

## Shipped (web-only, 5 files, +411/−3)

- `src-web/components/panels/ContextPanel.vue` — new "By category" section when
  `breakdown.categories` is present: stacked share bar apportioning measured token
  weights only (autocompact-buffer, tokens null, renders a row with "not measured"
  but never a segment; widths sum to 100% — pinned), per-category rows with
  swatch/label/token figure, drillable sub-rows (one open at a time, toggle),
  sub-figure precedence note → bytes (`2 KB` / `512 B`, TUI spelling) → tokens,
  `personal` badge as a real span, server-sent "not sent — this build splices no
  MCP server instructions into the prompt" rendered as plain text (no v-html, no
  anchors), and the `/context --raw` terminal hint line. Server strings stay in
  `{{ }}` interpolation only.
- `src-web/components/StatusBar.vue` — context meter span → `<button
  data-testid="context-meter" @click="panels.show('context')">` (keeps the meter
  span), plus `cache NN%` span (`data-testid="status-cache"`) from
  `state.turnUsage`: pct = cacheReadTokens / promptTokens rounded; prompt =
  read+creation+input when creation reported, read+input fallback when creation
  null (mirrors Tui Renderer.php:3090–3119); hidden when any leg null or
  prompt <= 0. [hot] file — minimal hunk, C-WEB-2 not live.
- `src-web/stores/contextSources.ts` — the existing single `connection.onEvent`
  callback now reloads on `turn.completed` for the panel's own session (guarded:
  sid non-null, sources loaded, sessionId match). `permission.asked` path
  byte-untouched. No protocol change (categories/buffer/mcpServers verified in
  committed `src-web/generated.ts` from the P-CTX.f weld).

## Tests (vitest, serial only — `npx vitest run --no-file-parallelism <2 specs>`)

- MODIFIED `panels/__tests__/contextPanel.spec.ts` (+6 tests: bar arithmetic,
  drill toggles, not-sent plain note, bytes/personal precedence, raw hint +
  no-section when categories absent, turn.completed reload incl. other-session
  no-reload and grown-figure re-render).
- MODIFIED `components/__tests__/statusBar.spec.ts` (+6 tests: meter is a button,
  opens context panel, null-pct shape; cache absent before usage, 90% OpenAI
  fallback, 86% Anthropic creation-arms, null input hides, live usage.updated
  frame drives 90%).
- Result: **Test Files 2 passed (2) · Tests 34 passed (34)** at `7f85a5ab6`
  (run twice post-recovery, stable).
- No NEW spec files → no durations/vitest-list changes. No php figures touched.

## Typecheck (`npm run typecheck` = vue-tsc --build)

My code is error-free (fixed an indexed-access TS2532/TS18048 in barSegments and
one 2-arg matcher in my new spec block). Remaining 6 errors are PRE-EXISTING
FOREIGN, not fixed per brief: `statusBar.spec.ts(200,41)/(209,73)` = committed
HEAD lines 199/208 (shifted +1 by my import line) and `reducer.spec.ts(307/312/
317/320)` — same `.toBe(x, 'msg')` pattern (TS2554) already on those files at
base; reducer.spec.ts is not in this lane's file set.

## Notes / incidents

- dist/ NOT rebuilt/committed (welder duty). node_modules via `npm ci` (the first
  dispatch ran in the wrong directory; retry log
  `/tmp/opencode/cc-cmp-npm-P-CTX.g-retry.log`, install verified by presence of
  node_modules + green suites).
- WORKTREE INCIDENT (recovered, zero damage): a base-probe `git stash pop` fired
  against a pre-existing FOREIGN `stash@{0}: On master: local-wip-before-merge`
  (pathspec miss — push staged nothing, pop applied theirs), leaving ~1,000
  unmerged paths in this worktree. Recovered by snapshotting the lane's 5 files,
  `git reset --hard HEAD`, restoring them; foreign stash entry left intact in
  refs/stash for its owner; suites re-proven 34/34 before commit. Lesson stamped:
  never blind `git stash push --<pathspec>`/`pop` in a shared-ref worktree.
- jsdom fact pinned: `wrapper.text()` joins adjacent flex spans without
  whitespace; the not-sent-row test asserts the label and note spans separately
  (visual gap comes from CSS `gap: 0.4rem`).
