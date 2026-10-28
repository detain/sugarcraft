# C-WEB-4.e weld STOP-with-record (batch-5, 2026-10-28, welder @ f2ecbff7c)

STOPPED at the wave's vue-tsc gate (same discipline as C-WEB-4.d batch-3 STOP-1.md).
Not a content conflict; lane held IN FLIGHT (branch cc/C-WEB-4.e + worktree kept;
NO §4 flip, NO weld commit; master carries ZERO lane-5 content after reset --hard).

## What was done and undone
- pick b0ea554b4 → e68270a09 (dist-only conflicts resolved by taking HEAD's dist;
  pick carried 13 files, zero dist paths — clean), then trio re-derive proven
  byte-identical (gen-protocol-schema --check rc0 "up to date"; npm run gen:protocol
  no diff), merged-tree dist rebuild, then:
- `git reset --hard f2ecbff7c` + `git clean -f -- candy-code-web/dist`.

## Gate results at merged tree (before reset)
- UNION proof PASS: fresh bundle index-DeNECm8l.js contains BOTH step-4
  turnStartedAt legs (2 hits) and the documents leg; 4.d/4.e share no source file,
  trio files touched only by 4.e (already union-correct at pick).
- phpunit legs NOT reached (STOP fired first); vitest legs NOT run (vue-tsc gate
  precedes them in the brief's order).
- vue-tsc `--build --force` → rc=2; sorted error-line delta vs
  /tmp/opencode/vue-tsc-cweb4c-base-e14.txt = 1 NEW lane-attributable error:
  `src-web/components/__tests__/composerDocuments.spec.ts(146,12): error TS7053:
  Element implicitly has an 'any' type because expression of type '0' can't be
  used to index type '{}'.`
  (line 146: `expect(emitted?.[3]?.[0]).toMatchObject({ name: 'paper.pdf' })` —
  VTU's emit-tuple type has no 4th element; runtime passes, typecheck reds).
- Log: /tmp/opencode/vue-tsc-cweb4e-merged.txt.

## Why r1 missed it
REVIEW-1.md gate "vue-tsc --noEmit rc=0" is the vacuous invocation documented in
lanes/C-WEB-4.d/STOP-1.md (root tsconfig is references-only; --noEmit checks
nothing). The real gate is `npx vue-tsc --build --force` and delta-vs-base.

## Cure wording (for fix-lane, any leg that satisfies --build)
Type the indexed access, e.g. `const tuple = emitted as unknown[] | undefined`
then `expect((tuple?.[3] as unknown[] | undefined)?.[0]).toMatchObject(...)`, or
declare the emit tuple's 4th slot in the mountComposer emits typing. Keep the
assertion's runtime meaning identical (documents arrive as 4th emit arg).

## Re-weld needs
- Lane fix commit on cc/C-WEB-4.e curing the TS7053; then re-run this brief's
  step-5 procedure verbatim (pick src+trio, strip lane dist, trio re-derive,
  dist rebuild, vue-tsc delta must be EMPTY, phpunit SessionSendDocumentsTest 7T
  + drift pair + TurnMethods neighborhood, vitest composerDocuments/sessionView/
  generated.spec serial, durations row, +8T carry).
