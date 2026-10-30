# C-TUI-2.g — Web checkpoint base selector (`files.diff` checkpoint)

Status: COMPLETE. Branch `cc/C-TUI-2.g` off master 7646af980. One commit (SHA cat-file-verified below at tip).

## What landed

- `candy-code/src/Protocol/Methods/FilesMethods.php` — `files.diff` gains an optional `checkpoint:int` argument. Server-side validation (named refusals, never silent HEAD fallback):
  - `ref` + `checkpoint` together → `invalid_params` kind `ref_with_checkpoint`;
  - `checkpoint` without `sessionId` → `invalid_params` kind `checkpoint_without_session`;
  - no session store → `unsupported` kind `no_store`;
  - no captured bases → `conflict` kind `no_checkpoints`;
  - `CheckpointDiff::of()` returning a string → `conflict` kind `checkpoint_diff_failed`.
  Bases come from `Checkpoints::fileCheckpointPositions()` (scan width `CHECKPOINT_SCAN = 1000`, same as the TUI diff-review loop) filtered by `WorkspaceCheckpointer::isCaptured()` — the same filter law `DiffReviewSource` uses. Counting matches `/diff n`: `$bases[min($checkpoint, count($bases)) - 1]`. Answer: `ref = "checkpoint:<index>"`, `diff` = the bounded patch, `truncated = omittedLines > 0`, plus two NEW result keys on BOTH paths: `checkpoints` (how many bases exist; best-effort 0 on the HEAD path when no sessionId/store — never refuses) and `omittedLines` (always 0 on the git path). Payload stays bounded by `CheckpointDiff` (2000 lines / 256 KiB); existing `MAX_DIFF_BYTES` git path untouched. No new network dials, no timers (E646-clean).
- `candy-code/src/Protocol/Schema/MethodSchemas.php` — `files.diff` params gain `$sid` sessionId ref + `checkpoint` integer(min 1) with describes; result requires `['ref','diff','truncated','checkpoints','omittedLines']`.
- `candy-code-web/src-web/components/panels/ChangesPanel.vue` — head gains a base `<select>` (`data-testid="changes-base"`, `v-if="panels.sessionId !== null"`): HEAD plus `k turn(s) ago` for k 1..`bases`. Degrades honestly: no session → no select and `{}` params (pre-existing pin at old L67 stays green); session but 0 bases → HEAD-only list (still rendered so the user sees there is nothing to pick — it does not fake options). Choosing a base reloads (`files.changed` still sends `{}` — only `files.diff` carries `{sessionId[, checkpoint]}`). Diff success records `checkpoints` into `bases` and clamps a stale selection; refusal clears the ref stamp; watch on `panels.sessionId` resets base and reloads. `diff-head` stamps `against <ref>` (`data-testid="changes-diff-ref"`) when a checkpoint base is active.
- `candy-code/tests/Protocol/FilesDiffCheckpointTest.php` — NEW, 7 tests / 49 assertions: checkpoint resolution against a real git repo (`CheckpointedRepoTrait` + UsageMethods-shaped live `ServerContext`/`WireClient` harness rooted at the repo), missing-`sessionId` refusal named, out-of-range checkpoint clamps to oldest base, uncaptured-only session → `no_checkpoints` (no silent fallback), `ref`+`checkpoint` mutual exclusion, HEAD path carries `checkpoints`/`omittedLines` counting the same bases.
- `candy-code-web/src-web/components/panels/__tests__/changesPanel.spec.ts` — in-step update: `headDiff()` fixture adds the two new result keys at all 6 old answer sites; new describe adds 6 selector tests (params naming, option list from `checkpoints`, pick→reload params+rendered checkpoint patch, 0-bases degradation, no-session degradation, refusal keeps the list alive, session-switch reset).

## Protocol trio (§1.8 regen → verify → restore)

- `php scripts/gen-protocol-schema.php --write` rewrote `candy-code/docs/protocol/candy-code.v1.schema.json`; `npm run gen:protocol` rewrote `candy-code-web/src-web/protocol/generated.ts`. Both VERIFIED against (drift guards green; vitest changesPanel 16 + generated.spec 3 green with regenerated files; project-scoped vue-tsc on the regenerated tree clears all 5 ChangesPanel errors — 2 FOREIGN `contextSources.ts` errors remain, see Gates) then RESTORED — `git status` at commit shows neither. **Welder: regenerate the PAIR in the weld commit; do not expect it in this pick.**
- `candy-code/docs/SERVER.md` was NEVER touched — the generator output required no SERVER.md change and `ServerProtocolDocumentationDriftTest` is green without it. **No `[docs:SERVER.md]` weld carry needed.**

## Gates (targeted only; cwd noted)

PHP (`candy-code`, `env -u LINES -u COLUMNS vendor/bin/phpunit -c phpunit.xml`):
- `--filter FilesDiffCheckpoint` → 7T/49A green (2 PHPUnit deprecations = pre-existing harness shape, UsageMethodsTest shows the same 2 at base).
- `--filter ProtocolSchemaDriftTest|ServerProtocolDocumentationDriftTest` → 13T/362A green (run with regenerated JSON, pre-restore).
- `--filter ReadmeRosterDrift|EnvRosterDrift|TrustKeyDocumentationDrift` → 59T/40837A green.
- `--filter DocFigureProseDrift|ReadmeJsonErrorContractDrift` → 111T, 4F — ALL 4 inside `ReadmeJsonErrorContractDriftTest` (schema-union/exit-code/import-refused arms), **base-proven foreign**: stashing only my two src files reproduces the same 4F at pristine base. NEW standing red — not in the brief's roster; add it (files: README + src/bin error machinery, unrelated inputs).

Web (`candy-code-web`, trio regenerated):
- `npx vitest run --no-file-parallelism src-web/components/panels/__tests__/changesPanel.spec.ts` → 16 passed.
- `... src-web/protocol/__tests__/generated.spec.ts` → 3 passed.
- `npx vue-tsc --noEmit` → exit 0 **but VACUOUS — corrected in FIX-1 round 1**: the solution-level tsconfig is `files: []`, so that invocation checks nothing. The real, project-scoped check at tip 98f6b0f4a, `node_modules/.bin/vue-tsc --noEmit -p tsconfig.app.json`, yields **7 errors**: 5 in `ChangesPanel.vue` (:52×2 the `{sessionId[, checkpoint]}` params not in the stale `MethodParams<'files.diff'>`; :75 and :76×2 `diff.value.checkpoints` missing from the stale result type) and 2 FOREIGN in `src-web/stores/contextSources.ts` (:176/:187, trust-ask `sessionId` — unrelated to `files.diff`). The regenerated pair (schema.json + generated.ts, verified then restored) clears exactly the 5 ChangesPanel errors — the regen supplies the params row (`sessionId` $sid-ref + `checkpoint` integer) and the result row (`checkpoints`/`omittedLines` integers); the 2 foreign ones remain. **Welder: run the project-scoped `-p tsconfig.app.json` on the regenerated tree and document what remains (expected: only the 2 foreign contextSources errors, if that lane has not landed its own cure).**

Mutation M1: stripped the `checkpoint_without_session` guard (cp backup `/tmp/opencode/C-TUI-2g-FilesMethods.bak`) → exactly 1F; restore md5-identical; suite re-green 7T/49A. Discriminating.

## FIX-1 round 1 (2026-10-30, review REVIEW-1 0C/0M/3m) — all three minors cured in-lane

- **MINOR-1 (path leak):** `FilesMethods.php` `checkpoint_diff_failed` now throws a FIXED literal `'the checkpoint diff is unavailable'` and sends the real reason to the server log channel via `$call->server->logError(new \RuntimeException($diff), 'files.diff checkpoint diff failed')` — the bg.spawn/ISS-057 precedent. Pinned both ways: the full JSON response must not contain the fixture root, the collected log line must contain the vanished gitDir. Mutations M1 (re-added the concatenation) and M2 (dropped the logError line) each reddened exactly the new pin.
- **MINOR-2 (vacuity):** added `testARequestAgainstAStorelessServerRefusesNoStoreByItsOwnName` (second Dispatcher over a `sessionStore: null` workspace → kind `no_store`) and the diff-failure pin above (captured checkpoint, `attachWorkspaceRef` rewrites the stored shape with a vanished gitDir; isCaptured still passes — status/ref/sha untouched). Mutations M3 (neuter the no_store guard) and M4 (delete the is_string guard) each reddened exactly its own pin. Test file now **9T/64A** (line 16's "7 tests / 49 assertions" is the pre-fix number; §7 carry grows accordingly — see FIX-1.md).
- **MINOR-3 (claim truth):** the vue-tsc sentence in Gates corrected above with the project-scoped measurement, the 5→0 regen-clears enumeration, and the 2 foreign residuals.
- Evidence: `FIX-1.md` in this directory (mutation logs, exact gate numbers, weld carries).

## For the welder

- NEW test file needing a `scripts/parallel-tests-durations.tsv` row: `candy-code/tests/Protocol/FilesDiffCheckpointTest.php` (7 tests; spawns a live ServerContext with real git — expect multi-second duration).
- Trio regen + `dist` rebuild belong to the weld commit (this lane commits neither; `suite-figure.json`/durations.tsv untouched).
- No SERVER.md hand-row carry (see above).
- i18n disclosure: protocol error strings are raw English per the file-local convention in `src/Protocol/**` (no `Lang::t` anywhere in that layer — honored, no `lang/en.php` keys minted); the `<select>` labels are web-side English strings (the web app is not `Lang`-catalgued). No user-facing candy-code PHP strings added → no en.php diff.
