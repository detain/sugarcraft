# C-MCP-2.c — REVIEW-1 (round 1, fresh reviewer)

VERDICT: FINDINGS (0 CRITICAL / 0 MAJOR / 1 MINOR / 2 NIT)

## Verified on disk
- Branch `cc/C-MCP-2.c`, single commit 58fff9bb6, author/committer detain 1/1, zero trailers; lane tree clean.
- Web-only: `git diff master...HEAD --name-only` = 4 files, all under `candy-code-web/src-web/` (Composer.vue, SessionView.vue, new composerMcpMentions.spec.ts, dormantRoster.spec.ts). No PHP, no protocol trio, `src-web/protocol/generated.ts` byte-untouched, `dist/` untouched, no suite-figure.json/durations.tsv touches, no phpunit files (vitest law honored).
- dormantRoster.spec.ts: the `mcp.resources` row removal is the in-step guard carry — the base row comment itself commanded "drop this row when it lands", and the spec's own derivation now sees SessionView's `request('mcp.resources'` call. Spec 3T green.
- TDZ sweep: `let mcpRoster` is declared BEFORE the `immediate:true` props.id watch that resets it (fix confirmed); all other new bindings (`mcpMentions`, `MCP_MENTION_PREFIX`, `fileSeq`/`lastSearchAt`/`mentionTimer`) precede the immediate mentionPartial watch in setup order; every search/completion helper is a hoisted function declaration. No TDZ class left in the diff.
- Reachability: `mentionPartial` regex `[^\s@]*` admits colons, `@mcp:` partials route via `runMentionSearch`; SessionView passes `:search-mcp-resources`; the two-colon insert matches C-MCP-2.a's server keyword form.
- Trust: popup rows render via mustache interpolation only; zero `v-html` in Composer.vue.
- Vacuity (traced, edits forbidden): deleting the empty-roster fallback branch reddens "an empty roster falls back to the file popup" (asserts `searchFiles('mcp:zzz')` + file rows); the catch fallback is pinned by the rejected-fetch test; the once-per-session cache is pinned by three `wire.sent('mcp.resources')` length-1 legs; prop-less file-only behavior pinned separately. Fallback-to-empty IS pinned.
- Gates (serial, --no-file-parallelism): composerMcpMentions + dormantRoster + sessionView + shutdownDormant + composerFilesImages = 27 passed (5 files); composerSuggestion = 5 passed. All green.
- vue-tsc: 20 error lines, zero in lane files; spot-verified contextSources.ts:176/:187, dormantRoster TS2532 sentence, statusBar.spec.ts:200 byte-present in master blobs of files the lane never touches — inherited, not introduced.

## Findings
1. MINOR (step-local, doc truth) — REPORT.md:3 claims "base 237224a7a = e91eb1d47-lineage master tip". False as written: master tip at review is 6742a2ad3 and the lead-declared base is e91eb1d47; 237224a7a is 10 commits BEHIND it (ancestor). Fix: correct the REPORT line to "base 237224a7a — cut ~10 commits behind the declared e91eb1d47 (batch-12/13 interval)".
2. MINOR (out-of-scope, welder duty) — consequence of #1: master's 237224a7a..HEAD range already moved SessionView.vue (e9838c105 C-SEC-6.c `answer(..., save)` hunk at old :90, adjacent to this lane's insertion block) plus StatusBar/PermissionCard/generated.ts. The cherry-pick is line-disjoint but contexts abut; the welder must pick + re-run the SessionView-adjacent vitest neighbors on the merged tree, and rebuild dist (welder rider, correctly deferred here).
3. NIT — Composer.vue Enter arm (~:661): when `mcpRows > 0` but `mcpMentions.value[selected.value]` is undefined it silently falls through to `completeFile` (mixed popup states cannot coexist in practice since each search clears the other; defensive branch just mis-prioritizes). No fix required.
4. NIT — the roster fetch failure is cached empty for the session (`mcpRoster ??= ... .catch(() => [])`), so a transient transport drop mutes `@mcp:` until session id changes. This matches the brief's "fetched once per session and cached" contract and the launch-frozen semantics of `mcp.resources`; documented in the SessionView docblock. Accepted.

## Verdict detail
Core feature is genuine, complete, honestly guarded, and test-pinned including the empty/failed fallback. Only carry is the stale-base REPORT misstatement (#1, one-line fix) and its welder-side consequence (#2, not the lane's to cure). No PHP/protocol/dist carries; no stubs; commit history sane.
