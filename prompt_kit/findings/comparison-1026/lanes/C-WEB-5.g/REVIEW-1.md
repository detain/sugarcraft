# C-WEB-5.g REVIEW-1 — VERDICT: FINDINGS C=0 M=2 MINOR=0 NIT=1

Lane: /home/sites/cc-cmp-C-WEB-5.g, branch cc/C-WEB-5.g, tip 323c05519 (re-read: `git log --oneline 288b81f11..HEAD` = 1 pick; `git status --porcelain` = 0 lines).
Design block design-web.md:709 read verbatim: files.modify = exactly the 4 lane files; tests list = 4 pins; no PHP file owned.
Diff `git diff --name-only 288b81f11...HEAD`: dormantRoster.spec.ts, shutdownDormant.spec.ts, AboutModal.vue, aboutModal.spec.ts — ALL index→index (no new files), no PHP, no protocol trio, no SERVER.md, no generated.ts, no dist.

## Gates (reviewer-run, this shell)
- vitest serial `--no-file-parallelism` 3 spec files (cwd=candy-code-web): Test Files 3 passed, Tests 17 passed (17). Matches builder claim.
- phpunit `-c phpunit.xml --filter '(ProtocolSchemaDriftTest|SettingsRemoteAllowlistTest|DrainTest)::'` (cwd=candy-code): OK — 45T/1316A, Deprecations: 2 (standing).
- vue-tsc --build (cwd=candy-code-web): 23 errors — 2 introduced by lane lines (MAJOR-1), 21 in files/regions the lane never touched (pre-existing tree state, incl. dormantRoster.spec.ts:60 on an untouched statement — its diff hunks are comments+roster row only).

## FINDINGS
- MAJOR-1 step-local — `candy-code-web/src-web/components/__tests__/aboutModal.spec.ts:192` TS2322: `demoteTo(scopes: string[])` is assigned into `principal.scopes` whose type is the `Scope` union (generated.ts:7); and `:238` TS2493: `confirmSpy = vi.fn(() => false)` types the call-tuple as `[]`, so `confirmSpy.mock.calls[0]?.[0]` is a type error. Both are lane-new lines; REPORT.md's "vue-tsc clean" claim does not hold at tip. Fix: `function demoteTo(scopes: Scope[])` with `import type { Scope } from '../../protocol/generated'`; and `vi.fn((_question: string) => false)` on the spy whose call args are read (:238 test; the `() => true` spies never index calls and are fine). Re-run `npx vue-tsc --build` and show the error count drops by exactly 2 (the 21 foreign errors are welded elsewhere, not this lane's duty).
- MAJOR-2 step-local (destructive-control transition, owner pre-ruled MAJOR) — reopen reset is implemented but UNPINNED: AboutModal.vue load() sets `stopping.value = false; stopError.value = null`, but no test opens → latches/refuses → closes → reopens and asserts the stale error row is gone and the button is enabled. Mutation reasoning: deleting both reset lines leaves all 17 tests green (fresh-mount tests never exercise the same-instance reopen path; the existing w2h reopen pin asserts only info/health/error, aboutModal.spec.ts:73-99). Fix: one pin in aboutModal.spec.ts — refusal lands stopError, `setProps({open:false})` then `{open:true}`, answer health/info, assert `[data-testid="about-stop-error"]` absent and the stop button NOT disabled.

- NIT step-local — aboutModal.spec.ts:225/:262 fixture codes: `wire.socket.fail(-32001, 'forbidden', …)` — ErrorCode.php:21-23 maps Unauthorized=-32001, Forbidden=-32003; the server's real under-scope refusal is -32003. Cosmetic (describe() at session.ts:346 shows message only, no code keying), but a future code-keyed handler would inherit wrong fixtures. Prefer -32003 (the pre-existing w2h test shares the shape — lane need only fix its own new lines).

## Q1 server-side gate — PASS
Scope enforced in PHP independent of UI: ServerMethods.php:46 registers `server.shutdown` `Scope::Admin` sideEffects; Dispatcher.php:344 `requireScope($spec->scope)` runs BEFORE param validation and handler for every call; CallContext.php:42-47 throws RpcError::of(ErrorCode::Forbidden). Shutdown() body (ServerMethods.php:216+) is structurally unreachable to an under-scoped token. Pin set: class-level live -32003 refusal (SettingsRemoteAllowlistTest:195, ContextInstructionWriteTest:162/:318 — both green at tip), registry-scope roster exact-equality (SettingsRemoteAllowlistTest scope table for settings family), artifact chain: ProtocolSchemaDriftTest (schema JSON + SERVER.md current — green) → generated.ts:1774 `"server.shutdown": "admin"` → lane's shutdownDormant 'the schema really scopes it to admin' pin. Observation (out-of-scope, closeout): no shutdown-SPECIFIC live PHP test calls the method under-scoped; the block owns no PHP file so the lane could not add one — recommend a closeout/owner mint.

## Q2 confirm/latch/re-arm — see MAJOR-2
Required confirm: PINNED (cancel sends nothing, test :218-232). Once-only latch: PINNED (double-click → 1 frame, :disabled, state hint — :234-254). Refusal re-arms LOUDLY: PINNED (:256-267 error row + enabled button). Reopen reset: implemented, UNPINNED → MAJOR-2.

## Q3 roster removal — PASS
Row removal is fail-closed legitimate: dormantRoster.spec derivedDormant() must equal DORMANT_REASONS keys, so a caller-less narrowing goes red. Caller REAL and reachable: App.vue:138 `about` button → aboutOpen → App.vue:158 mounts AboutModal → stopServer issues `connection.request('server.shutdown', {})` in a shipped module; shutdownDormant sole-caller scan asserts callers == ['../components/AboutModal.vue'] exact.

## Q4 handoff — PASS
Success: emit('close'), ConnectionBanner takes over via connection.shutdown (store connection.ts:137-141 reads event {reason,graceSeconds}; reset to null only on fresh hello :177). Dead-socket race: client.ts request() rejects when not connected and failAll on close → catch re-arms with honest error row, never a stuck 'Stopping…'. No reducer/store change in the diff → no new stuck-live state; modal shows nothing live after close; passive-event pin holds.

## Q5 generated surface — PASS
Diff contains no candy-code/docs/protocol/*.json, no SERVER.md, no generated.ts, no dist. WELDER DUTY (recorded): rebuild dist (`cd candy-code-web && npm run build`) on the merged tree, re-run the two roster specs there, and keep the sole-caller literal in sync if AboutModal relocates.

## Q6 wire-name pairs checked (both sides read)
health result ok/version/uptimeS/sessionsOpen/turnsRunning/draining (ServerMethods.php:152-161 ↔ MethodSchemas.php:129-137 ↔ AboutModal template+confirm ↔ spec fixtures); hello principal.scopes union incl 'admin' (MethodSchemas.php:114-117 ↔ connection.ts:74 ↔ canAdmin); shutdown params drainSeconds + auto-injected idempotencyKey (client.ts request()/IDEMPOTENT_METHODS ↔ ProtocolSchema.php:90-91 appends key to side-effecting params ↔ Dispatcher.php:354 optionalString) — test's `params == {idempotencyKey}` is the true wire shape; result {accepted} (schema ↔ fixture, value unconsumed); event {reason,graceSeconds} (EventSchemas.php:226 ↔ connection.ts:138-140 ↔ fixtures; reason literal 'server.shutdown was requested' matches Serve.php:605); METHOD_SCOPES admin (generated.ts:1774 ↔ registry :46). No desync found.

## Q7 absent-state honesty — PASS
Control renders only when admin AND a health answer is known (no invented counts); confirm text explicitly scopes to "As of the last health check"; refusal surfaces the server's own message via describe() (no code-fabricated text); load() clears feeds + stop state; no `??`-defaults in lane code that could read as real state.

## Q8 vacuity — PASS on landed pins
Reasoned each new pin against removal of its guarded behavior: canStop door removal→red; health-known door removal→red; confirm removal→cancel test red (sent non-empty); latch removal→2 frames red; close-emit/reducer removal→takeover test red; catch re-arm removal→refusal test red; scope pin red on generated.ts drift (PHP side drift-caught by ProtocolSchemaDriftTest, green); sole-caller scan red on second caller OR on caller removal (exact-equality). The ONLY unpinned transition is the load() stop-state reset (MAJOR-2).

## New test files (§7 inventory): NONE — all 4 paths are base-existing modifications (index→index hunks verified via git diff).

Reviewed read-only; no repo content touched; tree at tip still `status --porcelain` clean. background=true honored (no foreground holds; gates ran as bounded sync calls).
