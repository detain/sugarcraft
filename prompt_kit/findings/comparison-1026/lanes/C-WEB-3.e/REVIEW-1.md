# REVIEW-1 — C-WEB-3.e "Share link / Revoke on the session row" (0718479db, base 23fa8ab8e)

VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR.

## Verified
1. **Scope/diff**: `git show --stat` = exactly the 4 declared files, +406/−4. No `dist/`, no phpunit-forbidden files (suite-figure.json, durations.tsv untouched).
2. **Phantom law**: all four legs on disk — `sharesBySession` (sessions.ts:29, 3 refs), revoke menu leg (SessionSidebar.vue `<li v-for="row in shareRows...">` with prefix label, full `row.token` in `unshareRow`), clipboard guard (`navigator.clipboard` check + catch, `shareCopied` only on success), storage-absence assert (`webStorageDump()` scan in test 4).
3. **RPC match vs master**: SessionMethods.php registers `session.share` (Scope::Write, idempotent), `session.shares` (Scope::Read, optional sessionId → client's `{}` valid), `session.unshare` (Scope::Write, idempotent) — method strings match client calls exactly. Wire shapes (`items[]` fields, share result, `{revoked}`) match fixtures and `MethodResult` projections.
4. **Token hygiene**: zero localStorage/sessionStorage/indexedDB writes in diff (grep: only docblock prose + spec's clear/scan); zero console sites; menu renders `tokenPrefix` only (test 2 pins `not.toContain(FULL_TOKEN)` on the item); full token lives in dialog render + request frame only.
5. **E646/no-timers**: diff adds no setTimeout/setInterval anywhere; Esc closes the dialog ahead of the menu guard; dialog state is ref-only.
6. **canWrite gating**: `v-if="canWrite"` on Share item; `shareRows()` returns [] without write scope — test 3 discriminates both (store would show rows: the read-scoped listing IS answered).
7. **idempotencyKey honesty**: store mints none (payloads `{sessionId}`, `{token}`); protocol/client.ts:164 attaches it for the idempotent family (generated.ts:1573-74 lists share/unshare); spec `toMatchObject` is a true subset assertion and pins `days` absence.
8. **Stale-wins race**: server handlers (`shares()`/`publish()`/`revoke()`) are synchronous PHP under ReactPHP → responses FIFO in send order over one socket; client promise continuations queue in arrival order → `sharesBySession.value = next` unconditional is safe. Single-flight re-read pinned by test 2 (menu follows re-read). No pin needed for the impossible reorder; see NIT-1.
9. **Spec non-vacuity (failing-before, re-reasoned per test — empirical base-swap blocked by reviewer shell map, see limits)**: base blobs contain zero `session.shares`/`row-share`/`share-dialog` refs. T1 throws on empty DOMWrapper `.text()`; T2–T4 call `wire.answer('session.shares', …)` and harness `Wire.answer` throws `no session.shares sent` when the store never requested → 4F at base, holds. Mutation-reasoned: canWrite-neuter → T3 red; full-token label → T2 red; re-read removal → T2 red; any persistence → T4 red.
10. **Gates (run here)**: `npx vitest run sidebarShare --no-file-parallelism` → 4/4 pass (760ms). Neighbor superset `vitest run sidebar sidebarActions sidebarCloseRoot picker panels` → 14 files / 101 tests pass (brief's 29 was a narrower set; all green). `npx vue-tsc --noEmit` → exit 0 at lane tip AND at main-tree master tip 8856da5e9 — the claimed "2 pre-existing contextSources errors" do not reproduce (f90523394 base dirt evidently cured by later welds); disclosure only, no action.

## Findings
- **MINOR (step-local, weld-dispositioned)**: eslint leg unrunnable in this reviewer's permission map (`npx eslint`/direct binary refused; vitest/vue-tsc allowed). Welder/CI must run the web lint step on the 3 code paths.
- **NIT**: sidebarShare.spec.ts:29 `const URL` shadows the global `URL` (test-file only).
- **NIT**: `loadShares` keeps the previous map on refusal (docblock declares it); a future async `session.shares` handler would reintroduce a theoretical stale-wins window — worth a sequence guard only if that handler ever awaits.

## Reviewer harness limits
- Empirical base-swap impossible: reviewer shell map refused all writes into the worktree and `cp`/`ln`/redirect forms (read-only lanes law); failing-before settled by blob greps + harness source + per-test reasoning above.
- README bullet is truthful against the shipped behaviour (write-scoped rows, prefix labels, memory-only token, revoke-from-any-browser).
