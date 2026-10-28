# C-WEB-7.b — SKIPPED at weld (semantic conflict; left in flight)

Welder: cc-cmp welder · 2026-10-28 · master after lane 1 = 4b771634d.

## Verdict
C-WEB-7.b (cc/C-WEB-7.b @ 06364f419, base 581ac6ede) cherry-picks CLEAN (zero textual
conflicts — 816e9b67c on master), but the merged tree REDDENS the lane's own spec:

```
FAIL questions.spec.ts > question announcement repeats and trust prompts (WEB-7-P1)
     > a trust prompt adopted by the Context panel store is announced once
AssertionError: expected '' to be 's1 — project trust prompt for /srv/app is waiting…' (spec :117)
```

- Lane tree (base 581ac6ede): the 4 touched specs = 28/28 green.
- Merged tree (master 4b771634d + pick): 27/28, this one red, deterministic.
- Pick reverted (`git reset --hard 4b771634d`); worktree + branch untouched, lane in flight.

## Root cause — colliding steps: C-WEB-7.b × C-SEC-5.b (f90523394)
File sets are disjoint (contextSources.ts is not in C-WEB-7.b's 9 files), but C-WEB-7.b
READS a store contract that C-SEC-5.b rewrote after the lane's base:

- Lane `src-web/lib/questions.ts:70` watches `context.pendingTrust` (the C-SEC-4.b
  singular field; docblock at :66 names it).
- C-SEC-5.b renamed the surface to plural `pendingTrusts: TrustPrompt[]` with per-target
  rows (`contextSources.ts:52`, exported at :170 `return { …, pendingTrusts, … }`).
- Result on the merged tree: `context.pendingTrust` is undefined, the watcher never
  fires, the trust sentence is never announced → WEB-7-P1(b) is dead code post-merge.

## Fix owner / re-land recipe (for the lead — welder does not fix code)
Rework in the lane (fresh commit on cc/C-WEB-7.b rebased onto master ≥ f90523394):
1. questions.ts: watch `context.pendingTrusts`; announce each unannounced row once
   (the "re-delivery must not announce twice" leg needs a per-askId seen-set keyed on
   `askId`, since the array merges rows instead of holding one).
2. Trust sentence wording: verify against C-SEC-5.b's target-aware shape ('mcp'
   target / root / 'waiting in the Context panel') — spec hardcodes
   `s1 — project trust prompt for /srv/app is waiting in the Context panel`.
3. Re-run the 4 touched specs serially, then the weld duties (dist regen ride,
   §4 mark, run-state 112→113). No phpunit durations row (vitest-only lane).

## Notes
- Prompt's "5 touched specs → 36/36" figure: the pick carries 4 spec files / 28 tests;
  the 5th/36 did not materialize (arithmetic differs from lead's ledger — same class
  as NIT1 report-arithmetic, not a defect).
- NIT2 seam stands as briefed for when the lane lands: promptHistory.ts hardcodes
  STORAGE_KEY duplicating storage.ts's unexported PREFIX (out of declared files).
