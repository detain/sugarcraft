# C-WEB-5.g — Confirmed `Stop server` for Admin tokens — CLOSED

## Verdict
DONE, single commit on `cc/C-WEB-5.g` (base master 288b81f11, picks=1):
`323c05519e9a3529088c1cf740d4bd36bdc30030` (cat-file -t HEAD = commit, same shell;
%ae=%ce=detain@interserver.net verified by SEPARATE greps; zero Co-Authored; tree clean).

## Resume discrepancy (owner asked to flag)
The owner's resume note listed dirty files `candy-code/tests/Host/WebLogTailServeWiringTest.php`,
`components/modals/AboutModal.vue`, `src-web/lib/export.ts`, `src-web/stores/app.ts`.
NONE of those exist in this worktree at any point. First-action probe showed the dirty set was
exactly my four step files; HEAD was still at base, picks=0. No second writer evidence; I did not
revert anything and continued my own step. The listed set looks like a stale/foreign-lane read
(possibly the web-log-tail lane — that is R13's other half, not this step).

## What shipped
- `candy-code-web/src-web/components/AboutModal.vue`: `stopping`/`stopError` refs, `canAdmin`
  (house gate shape from SettingsView:34), `canStop = canAdmin && health known` — the control is
  not offered while blast radius is unnameable. `stopServer()`: guard, window.confirm naming
  `N running turn(s)` + `M open session(s)` "As of the last health check" + drains/exits/drops +
  no-auto-restart; cancel sends nothing; once-latch (double-click sends one frame); refusal ->
  `about-stop-error` role=alert + re-arm; success -> emit close, draining banner takes over.
  load() clears stop state on re-open. Template `<p v-if="canStop" class="stop">` after the
  health block; `.stop`/`.danger` scoped styles.
- `src-web/__tests__/shutdownDormant.spec.ts`: FLIPPED negative control -> positive sole-caller
  pin (schema-admin kept; callers == ['../components/AboutModal.vue']; passive event-without-send
  test kept); docblock truth-flipped.
- `src-web/__tests__/dormantRoster.spec.ts`: `server.shutdown` DORMANT_REASONS row REMOVED in-step;
  header + mcp-resources-area comments truth-flipped.
- `src-web/components/__tests__/aboutModal.spec.ts`: +5 pins (non-admin no control; health-unknown
  no control; cancel names counts + sends nothing; confirmed sends exactly ONE frame with the
  client's injected idempotencyKey and NO drainSeconds override, then {accepted:true} closes and
  the server.shutdown EVENT arms connection.shutdown for the banner; refusal loud + re-armed).
  vi.stubGlobal('confirm') house idiom + afterEach unstubAllGlobals.

## Red-before proof
Pre-implementation run of the three specs: `Tests 5 failed | 12 passed (17)` (sole-caller scan,
roster equality, and the three stop-click pins all red). Post-implementation:
`Test Files 3 passed (3) · Tests 17 passed (17)` — serial `npx vitest run --no-file-parallelism`.
`vue-tsc --noEmit` clean. First green run surfaced the real wire shape (client.ts:164 injects
idempotencyKey on mutating methods once per request; resend-once-after-reconnect rides the same
key) — test corrected to reality, not a fabricated `{}`.

## Files/figurers for §7
- NEW test files: NONE (all four files modified in place).
- Protocol trio: UNTOUCHED — no regen/restore needed; no new method or event.
- phpunit: not exercised — zero PHP files touched by this step.
- dist: NOT rebuilt/staged (weld-only duty). WELDER: `npm run build` once per wave (AboutModal
  changed); re-run `dormantRoster.spec.ts` + `shutdownDormant.spec.ts` on the MERGED tree —
  C-WEB-5.f (logs) and any modals/ relocation lane may re-route the AboutModal path, in which
  case the sole-caller literal '../components/AboutModal.vue' must move with the file.
- candy-code-web/README.md: zero `shutdown|dormant|About` prose found — NO doc row needed from
  this step (design-block "soft" case); nothing for the welder to collect.
- i18n: src-web has NO locale catalog (raw-English literals are the house web convention —
  keys.ts is a Vue InjectionKey file); no LangParity widening applicable; refused inventing one.

## Refusals/decisions
- Kept confirmation non-bypassable per R13: window.confirm is the interaction door AND the
  content gate — the control does not render unless the counts needed to describe the blast
  radius are known (a health-unknown click-through would have to misrepresent them).
- Did NOT add a drainSeconds knob (server defaults to configured grace) and did NOT touch the
  ADMIN-scope server-side surface — serve-side refusal machinery already exists (Scope::Admin
  METHOD_SCOPES); the loud-refusal client row is pinned for the mid-session scope-change case.
- Foreign-red baseline respected; none of my filters touched them.

background=true honored (resumed child, single writer).
