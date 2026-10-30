# C-TUI-2.g REVIEW-1 — Web checkpoint base selector (tip 98f6b0f4a, base 7646af980)

VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 3 MINOR (all step-local). Weld-ready after fixer or with minors disclosed to weld; §4 carries below are authoritative.

Gates reproduced (cwd candy-code, plain pipe, env -u LINES -u COLUMNS):
- FilesDiffCheckpoint: 7T/49A green, 11.5s (2 deprecations = harness-wide, seen on all filters incl. base-derived families).
- ProtocolSchemaDrift|ServerProtocolDocumentationDrift on the COMMITTED tree: 13T/361A, 1F = ProtocolSchemaDriftTest:39 only, and its diff is EXACTLY the files.diff entries (params sessionId+checkpoint, result 5 keys). This is the §1.8 restored-trio state BY DESIGN — regen at weld flips it green. ServerProtocolDocumentationDriftTest arms are GREEN pre-regen.
- ReadmeRosterDrift|EnvRosterDrift|TrustKeyDocumentationDrift: 59T/40837A green — matches claim byte-exact.
- DocFigureProseDrift|ReadmeJsonErrorContractDrift: 111T/4F — all 4 in ReadmeJsonErrorContractDriftTest (:935/:974/:1046 + schema-union arm); none of the lane's 5 files is README/bin/src-error-machinery → foreign; standing roster confirmed without needing the stash trick.
- vitest --no-file-parallelism: changesPanel.spec 16 passed; generated.spec 3 passed (committed tree, old trio self-consistent).

## 1. Trust boundary — PASS with one minor
- Bases: Checkpoints::fileCheckpointPositions($store,$sid,1000) → bounded (redo ≤1 + listCheckpoints SQL LIMIT); WorkspaceCheckpointer::isCaptured enforces REF_PREFIX + /^[0-9a-f]{40,64}$/ sha, so no hostile-store shape injection reaches CheckpointDiff. sessionId pattern-checked via SessionMethods::sessionId. Patch payload is CheckpointDiff-bounded (2000 lines/256KiB, control chars/UTF-8 scrubbed per its class law). ref answer = "checkpoint:". int-cast → safe. No dials/timers (E646-clean); the git work rides the pre-existing RESTORE_BUDGET_SECONDS child budget.
- MINOR-1 (step-local): the checkpoint_diff_failed message embeds the prepare()/refusalFor() reason which can carry server absolute paths ("the repository that held this snapshot is gone: <gitDir>", "the project directory <path> does not exist") onto the wire. Precedent exists (refuseFailure sends git-stderr line) but the kind already carries the machine meaning; cure: fixed literal message, drop the concatenation.
- omittedLines is honest: always 0 on git path (documented in schema describe), real count on checkpoint path; truncated === omittedLines>0 there.

## 2. Argument validation — PASS
- optionalInt('checkpoint', 1): absent→null (HEAD path byte-preserved); 0/negatives/non-int refused by Params::int (min 1, exhaustive — no negative index possible); huge clamps to oldest ($bases[min($cp,count)-1], mirrors DiffReviewSource:86 min(max(1,n),bound)); ref+checkpoint refused BEFORE session check (right order, test-pinned); checkpoint-without-session refused named (builder's M1 mutation proved: exactly 1F).
- MINOR-2 (step-local): two named refusals are UNPINNED — no_store and checkpoint_diff_failed (guard removal → TypeError / unchecked string use, no test reddens). Cure: two pins (store-less ServerContext; corrupt captured gitDir post-capture).

## 3. Schema truth — PASS
MethodSchemas params $sid-ref + ref(128) + integer(1); result requires exactly the 5 keys BOTH paths emit (verified line-by-line). Pre-regen drift is exactly files.diff (§above). SERVER.md truly owes nothing: ServerProtocolDocumentationDriftTest green pre-regen, MethodSpec summaries unchanged → NO [docs:SERVER.md] weld carry (builder claim CONFIRMED).

## 4. Generated-artifact hygiene — PASS; weld carries (explicit)
Commit = exactly the 5 named files; no schema.json, no generated.ts, no dist, no suite-figure.json/durations.tsv. WELD MUST: (a) candy-code: scripts/gen-protocol-schema.php --write + npm run gen:protocol (pair — generated.spec passes now only because old trio is self-consistent; regenerating ONE without the other breaks it); (b) rebuild dist; (c) vitest --no-file-parallelism on the regenerated tree (changesPanel 16 + generated.spec + full protocol family) + ProtocolSchemaDrift green; (d) durations.tsv row for candy-code/tests/Protocol/FilesDiffCheckpointTest.php (measured 11.5s, real git + live ServerContext); (e) suite-figure + README pair re-pin: +7T (+49A floor) for the new file; (f) NO SERVER.md row.

## 5. Frontend contract — PASS with one minor
Spec is load-bearing: 6 new tests assert params sent ({sessionId} / {sessionId,checkpoint} / no-session {}), option list from checkpoints, base pick → checkpoint patch rendered + OLD HEAD text gone, changes-diff-ref stamp 'against checkpoint:11', refusal keeps list alive (changes-count 3), session switch → base resets to 0 and re-asks. Honest degradation each pinned (no session → no select; 0 bases → HEAD-only). Old L67 pin region untouched except the fixture-key helper — still asserts params {} for the no-session call.
- MINOR-3 (step-local, claim truth): "vue-tsc --noEmit → 0 errors" is vacuous — the solution tsconfig is files:[] so it checks nothing (I measured exit=0). The real check `vue-tsc --noEmit -p tsconfig.app.json` at the committed tip shows 7 errors: 5 in ChangesPanel.vue (:52 x2 sessionId/checkpoint params, :75/:76 checkpoints result — all resolved by gen:protocol regen) + 2 foreign in stores/contextSources.ts (:176/:187, trust-ask sessionId — likely also regen-resolved; welder re-runs and only documents what remains). Report should have measured the project-scoped invocation; the tip alone does NOT type-check pre-regen.

## 6. Whole-lane scan
Conventions OK: strict_types, final, PSR-12, one type per file, no new user strings in PHP UI layer (protocol-layer no-Lang convention honored and disclosed; select labels are web-side, non-Lang-catalogued — matches repo law). No TODOs/stubs. No new proc_open sites. Comments say why. Doc-truth: schema describes match behavior; README sentence "0 when no sessionId/no store" is literally true (checkpointBaseCount best-effort, never refuses).
