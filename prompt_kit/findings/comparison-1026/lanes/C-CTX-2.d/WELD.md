# C-CTX-2.d — WELD

Lane tip 566456295b67df444c182f268facdec76c6e0eb8 (base e4a705244, 1 commit, 12 files) → master pick 15c1f71fcf1a6be500f58726424d1d097eddb020, cherry-picked CLEAN (zero conflicts; patch-id stable 221563bf095bc2ff8f48e865c84253a6396d7525 identical both sides; author/committer detain@interserver.net, zero trailers).

Chain: pick 15c1f71fc → regen rider 8d0ffa371f0859ce9002a2c1fd150bf8130337f9 → durations 15dc9c53e9420b659c1d0ff56f81e3078e5b3c3a → this weld commit.

## Regen (PROTOCOL trio — owed, pick touches src/Protocol/EventType.php + Protocol/Schema/EventSchemas.php)

`php scripts/gen-protocol-schema.php --write` + `npm run gen:protocol` + `npm run build` on the merged tree.
- schema JSON +38 lines; SERVER.md +2 rows EXACTLY (`context.changed` and `prompt.suggested`, session/live); generated.ts +12 lines, 4 references to the new names, `durable:false scope:"session"` both.
- Note: run-state roster cited `candy-code/tools/gen-protocol-schema.php` and a fileset naming `Protocol/Methods/PromptMethods.php + Schema/Definitions.php` — disk truth is `candy-code/scripts/gen-protocol-schema.php` and EventType.php + Schema/EventSchemas.php; regen owed either way, executed.
- dist rider bundle names: index-BN34gdZx.js (was index-C0wZxEBm.js), index-BmMQOzik.css (was index-Cd2YqZsf.css); index.html + manifest.json updated in same commit.

## Typecheck self-heal (MINOR-3 disclosure)

Post-regen `npm run typecheck` = 20 raw error lines; the raw pre-existing multiset measured on the lane tree (28 − 8 lane-caused) is EXACTLY the same 20 lines → zero net new, self-heal PROVEN. Roster/reviewer counted 19 pre-existing / 23 committed / 4 new (TS2344 reducer.ts:430 + TS18046 :431×3); raw disk census at weld was 20/28/8 (vue-tsc double-reports the two contextSources.ts sites; the 6 reducer.ts data-unknown lines accompany the TS2344 group). Same conclusion either count; corrected figure recorded here and in the rider commit message.

## Merged-tree gates

- ServedPromptSuggestionTest: 4T/48A OK (2 PHPUnit deprecations = standing).
- Guard family `DuplicatedTestHelperDrift|StderrEmitterCensus|ReadPathCensus|GlobDialectDifferential|TreeWideGuardRoster`: 158T EXACT / 26195A vs lane-claimed 26137 (+58A tree-scan recount of the +38-line regen schema and foreign welds since lane base — tests count identical, zero failures).
- `ProtocolSchemaDriftTest|ServerProtocolDocumentationDriftTest|EventTypeTest`: 16T/481A OK — drift pair GREEN against the regenerated artifacts.
- Vitest (--no-file-parallelism): new composerSuggestion+contextChanged 9/9; panel/roster contextPanel 19 + dormantRoster 3 = 22/22 EXACT; protocol dir 38/38 (lane disclosed 31/31 undercount — generated.spec.ts 3 included and green); adjacent component/stores set 70/70 + 137/137 combined stores/panels/protocol run — all passed, zero FAIL.

## Review dispositions

- MINOR-1 (REPORT figure): CURED pre-weld — 99T/679A replaced by 187T/1083A with corrected filter rationale, re-measured green on the lane tree.
- MINOR-2 ACCEPTED: tests/Host/SessionHostTurnSettleWorkTest.php is an undeclared 3-line docblock-only touch (outside the brief's declared set; disclosed as lane deviation 7; the sibling env-clear it describes pre-existed at base; comment-only, no behavior, safe — no concurrent claimant at weld).
- MINOR-3: typecheck committed-state number now disclosed in REPORT dev-5 + this file (see above).
- NIT-1 (typing-clear wording): carried — implementation hides the ghost but store clears on turn.started/snapshot (deliberate TUI parity); no spec pins the design sentence; fold or pin later.
- NIT-2 (double-refetch): carried — context.changed{settle} + existing turn.completed arm refetch twice per settle (immediate + debounced, generation-latched); follow-up could fold the turn.completed arm into scheduleReload.
- NIT-3 (/memory over-announce): carried advisory — read-forms announce context.changed too (name-keyed per design; consumers treat reloads as advisory).

## Standing reds in scope

No foreign reds fired in this lane's gate set (staleness pair not run — suite-figure.json untouched per §1.6; guard family fully green).
