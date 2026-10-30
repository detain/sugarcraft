VERDICT: FINDINGS — 1 MAJOR (step-local), 1 MINOR (step-local), 1 MINOR (out-of-scope), 1 NIT.

Scope: MAJOR — fix the 13 vue-tsc TS2532s; then weld-ready.

## F1 · MAJOR (step-local) — vacuous type-check claim; 13 real type errors in the lane's NEW spec files
- Builder claim "vue-tsc --noEmit exit 0, 0 errors" is the known trap: `candy-code-web/tsconfig.json` is `"files": []` (references only), so the bare command checks nothing.
- Project-scoped truth gate `npm run typecheck --prefix <wt>/candy-code-web` (= `vue-tsc --build`, covers app+vitest+node+e2e): **34 errors = 21 foreign baseline + 13 LANE-INTRODUCED**, all TS2532 "Object is possibly 'undefined'" from indexed accesses under `noUncheckedIndexedAccess`, exclusively in the two NEW files:
  - `src-web/components/__tests__/codeBlockCopy.spec.ts` :51, :52, :65, :70, :75, :76 (`buttons[0]/[1].classes()/text()/trigger(...)`)
  - `src-web/components/__tests__/transcriptActions.spec.ts` :153, :157, :182, :202, :223, :228, :293 (`confirm.mock.calls[0][0]`, `wire.sent('command.exec')[0].params`)
- Foreign baseline 21 lives only in untouched files (contextSources.ts ×4, contextPanel.spec 8, reducer.spec 4, statusBar.spec 2, dormantRoster.spec 1, contextPanelEdit 1, contextTrustRouting 1).
- Cure: guard or optional-chain the 13 sites (the same file already uses the `frame?.id` idiom at :274; an assertion against `undefined` still fails correctly, so `?.` is semantics-safe). Re-run `npm run typecheck` — must return to exactly the 21-error baseline.
- Note the foreign baseline itself contains the identical TS2532 shape, so the repo does not gate typecheck in CI — but a lane must not ADD errors, and the builder's 0-errors claim was produced by the vacuous invocation.

## F2 · MINOR (step-local) — rewindRow is not single-flight
- `src-web/views/SessionView.vue` rewindRow: two rapid confirms (double dialog-ok, or clicking two rows' buttons before the first exec settles) fire TWO `command.exec rewind` calls computed from the SAME pre-refetch `items` snapshot; the second rewinds further back than the user confirmed (steps recomputed against a stale list). Recoverable via `/redo`, but silent.
- Cure: a `let rewinding = false` guard around the await in rewindRow (return while set), or recompute steps from post-refetch state. No existing pin covers concurrent invokes.

## F3 · MINOR (out-of-scope) — clipboard is a new raw-bytes sink
- `navigator.clipboard.writeText` receives un-sanitized transcript/code text. Host egress applies no ANSI/PUA sweep on stored assistant/user content (only the TUI paints through `Sanitize::untrusted` at render; Chat's picker-field sweep at Chat.php:15984 is picker-local). A model emitting OSC-8/control bytes could plant them in the user's clipboard (paste-into-terminal vector).
- Not a lane regression: the design block explicitly mandates clipboard copy of transcript text, and the display already shows the same string; the right fix site is host egress or a shared `sanitizeForClipboard` helper — flag for the lead/weld ledger, no action required from this lane.

## F4 · NIT — decorate() harness workaround verified honest
- The disclosed DOMPurify×happy-dom fragment-leading `.code-block` drop is real and documented in-source (`MessageMarkdown.decorate`), and the shipped anchor logic `button.closest('.code-block') ?? pre` is a correct superset: in a real browser the wrapper survives (position:relative pin at `.markdown :deep(.code-block)`), and even if it did not, the fallback keeps the button functional with slightly degraded placement. Test-only, production behavior unchanged. No action.

## Priority-check ledger (all verified on disk, worktree tip 17354d9cc)
1. vue-tsc: F1 above.
2. Rewind `n` law: CONFIRMED sound. `itemsFromRow` (reducer.ts:207) drops `userVisible===false` rows entirely and maps `uiOnly:true` user rows to `notice`, so every visible `kind:'user'` item ↔ exactly one dispatched prompt ↔ one checkpoint (`saveCheckpoint` runs per dispatch at SessionHost:2435 before the turn). Host newest-first `listCheckpoints` + `restoreCheckpoint` undoes `index >= target`; the redo-tip row carries CHECKPOINT_REDO_TIP status, excluded by the `undone=0` filter — no contamination. The only break directions (prune cap / failed checkpoint save) clamp to UNDER-rewind (less lost than promised) and are disclosed in the confirm/docblock. Pins assert exact `'3 --chat'`/`'1 --chat'` strings incl. the echo-between case → an off-by-one in either direction turns the argument string wrong and reddens. Confirm preview shows the first 60 chars of the clicked prompt + explicit "this prompt and everything after it disappears" — adequate to catch a surprise.
3. Sequencing: declined confirm → zero exec (pin :144). Failed/refused exec → host THROWS RpcError (ui_only -32030 / command_refused / notFound) → JSON-RPC error frame → client request rejects → catch surfaces, NEVER seeds (pin :262 socket.fail). Busy ⇒ loud error + zero exec (:174). `{error,code}`-as-resolved-result never occurs from this host (CommandMethods verified). seed-before-settle mutation ⇒ 2 red (M4 reproduces). No `session.send` on any path (pin asserts 0). No double-exec pin → F2.
4. Clipboard: gate is the exact notifications precedent (`settings.ts:53` `isSecureContext === false`); absence ⇒ loud "couldn't copy" state, never silent; trailing `\n` trimmed, read at CLICK time (pin :89 mutates DOM then copies changed text). Untrusted-content posture → F3.
5. decorate(): F4, honest.
6. Scope: `MessageMarkdown.vue`/`Transcript.vue` ARE named in the design block's files.modify (block-declared, not creep); code-block copy genuinely requires MessageMarkdown (it owns the rendered fences) and the relay through Transcript is the minimal emit path. Diff = exactly the 8 claimed files, +702/−10, zero `candy-code/**`.
7. Client-only claim holds; in-step guard law N/A (no docs/rosters/protocol touched); no dist committed.

## Gates reproduced (serial, local bins)
- `vitest run --no-file-parallelism <the 2 new specs>` → Test Files 2 passed, Tests 25 passed.
- `vitest run --no-file-parallelism` (full package) → **Test Files 91 passed (91), Tests 742 passed (742)**, 54s — superset of the claimed 606/74-file window and 14/14 src-web/__tests__; all green.
- `npm run lint` → 3 errors, ALL foreign untouched files (Composer.vue:214, HelpOverlay.vue:14, contextTrustRouting.spec.ts:31); zero on lane files — claim true.
- No phpunit warranted (zero PHP touched).

## §4 weld carries
- REAL project-scoped vue-tsc: 34 errors at lane tip (21 foreign baseline + 13 lane); after the F1 fix expect exactly 21. `npm run typecheck` (`vue-tsc --build`) is the non-vacuous gate; bare `vue-tsc --noEmit` checks nothing (`tsconfig.json` `files: []`).
- dist rebuild REQUIRED (src-web changed; lane correctly did not commit dist).
- No durations.tsv rows (vitest specs, no new candy-code/tests files). No suite-figure.json change (no PHP/tests added). No trio/protocol regen (no host surface touched).
- GAP-UI-32 web-half deferral CONFIRMED intentional: zero C-WEB-4-scope files in the diff; design assigns it to C-WEB-4.
- Hot-file serialization: TranscriptItem.vue/SessionView.vue/session.ts are [hot] — welder keeps wave ordering against sibling lanes; lane itself touched no forbidden surface.

NOTE: written to the MAIN TREE (my edit map refused the worktree path); the welder must copy this file onto branch cc/C-TUI-3.b.
