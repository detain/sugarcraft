# C-MCP-2.c — Web composer `@mcp:` resource picker — CLOSED

Branch tip: `58fff9bb607965b4eaf88554eefb0c4d5d5306aa` (cat-file-verified commit, base 237224a7a — an ancestor of the declared base e91eb1d47, cut 48 commits BEHIND it (measured: `git rev-list --count 237224a7a..e91eb1d47` = 48, full and first-parent), not the master tip). Author/committer detain 1/1 separate greps, zero Co-Authored trailers.

## Files (net +318/−14, 4 files)
- M `candy-code-web/src-web/components/Composer.vue` (+117/−12): `McpMention` exported type, `searchMcpResources` prop, `@mcp:` partial routing (`runMentionSearch`), row list capped 8 sharing the file popup keyboard (arrows/Tab/Enter accept, never send), insert form `@mcp:<server>:<uri> `, empty/rejected roster falls back to `fs.search` on the same partial, without the prop the popup stays file-only.
- M `candy-code-web/src-web/views/SessionView.vue` (+49/−1): `mcpResourceRoster()` fetches `mcp.resources` once per opened session (cached promise, reset on session-id change), `searchMcpResources` filters server/uri/name; not-started/failed caches empty rows → composer fallback; prop passed beside `searchFiles`.
- A `candy-code-web/src-web/components/__tests__/composerMcpMentions.spec.ts` (162 lines, 8 tests): rows listed with prefix-stripped query and no file call; Enter accept emits two-colon keyword, no send; arrows+Tab share keyboard; empty roster → files; rejected fetch → files; no prop → file-only; SessionView fetches once (wire.sent count 1 across three queries, uri/name legs live); not-started caches empty.
- M `candy-code-web/src-web/__tests__/dormantRoster.spec.ts` (+3/−4): `mcp.resources` row removed — the spec's own call-site derivation now sees the SessionView caller.

## Aborted-builder remainder vs finisher work
Builder left the whole feature uncommitted but essentially complete. Finisher fixed two real defects: (1) spec imported the Wire harness at the nonexistent `../../grid/__tests__/harness` (correct path `../grid/__tests__/harness`); (2) TDZ crash — the `immediate: true` session-id watch assigned `mcpRoster` before its `let` declaration, throwing on every SessionView mount (2 spec tests red). Moved the declaration above the watch; both reds green.

## Gates (all serial vitest, `--no-file-parallelism`)
- `composerMcpMentions.spec.ts`: 8/8 passed (after fixes; before fix 6/8 with 2 reds naming the TDZ).
- Neighbour/regression `dormantRoster + composerFilesImages + composerSuggestion + shutdownDormant + sessionView`: 24/24 passed, 5 files.
- `npm run typecheck` (vue-tsc --build): 20 errors, ALL pre-existing at base (contextPanel 8, reducer.spec 4, contextSources 4, statusBar 2, contextTrustRouting 1, dormantRoster 59 TS2532 — identical line present in `HEAD:` pre-lane file); ZERO in lane-authored files; RC reported 0 by the npm wrapper, error list identical modulo line shifts.
- No PHP files touched → no phpunit run. Protocol source untouched → generated trio + `src/generated.ts` byte-untouched. `dist/` untouched (welder rider: src-web changed, rebuild dist at weld per the C-SES-7.e precedent).

## New-test-file / durations carry
`composerMcpMentions.spec.ts` is a vitest spec — no `scripts/parallel-tests-durations.tsv` row needed (vitest law).

## Seams
- Fleet-wide `vue-tsc --build` is red at base (20 inherited errors listed above) — gate owners should not attribute them to this lane; a dedicated typecheck-hygiene step is not part of this plan.

## Erratum
- 2026-10-29 (round-1 review, MINOR #1): line 3's "base 237224a7a = e91eb1d47-lineage master tip" corrected in place — 237224a7a is an ANCESTOR of the declared base e91eb1d47, cut 48 commits behind it (measured with `git rev-list --count`, both full and first-parent; REVIEW-1.md's "~10" estimate understated the gap). No other claim in this report altered. Review #2 (welder SessionView.vue churn over 237224a7a..master + re-run SessionView-adjacent vitest neighbours + dist rebuild rider) and NITs #3/#4 stand as weld-carries, unchanged.
