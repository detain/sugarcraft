# C-WEB-5.e — FIX-1 (round 1)

## §1 Predecessor + clean slate
Prior round-1 fixer incarnation returned a PHANTOM completion citing sha 71432860a
(disk-verified nonexistent; session retired). This lane started clean: grounding
read at session open showed tip c8b76091b (picks 02e18c14c + c8b76091b on base
444bb0f70), porcelain 0, REVIEW-1.md restatted 9,191 B @ 2026-10-31 15:39:58 and
read in full. Nothing from the phantom existed to salvage; the cure below was
written fresh.

## §2 Cure — MINOR-1 (kind-allowlist vacuity), EXEC-PROVEN
Finding: sw.ts:105 `record.kind === 'question' || record.kind === 'finished'`
had no discriminating fixture — widening it to any string kept all 20 push.spec
pins green while an unswept kind would ride the `candy-code:${kind}` dedupe tag
(sw.ts:132), defeating collapse, and the reviewer's mutation proof was
map-denied (UNVERIFIED-BY-EXEC there).

Cure (commit c40dfd266, ONE new pick; the two reviewed picks untouched; file
candy-code-web/src-web/stores/__tests__/push.spec.ts, +31 lines, two new sw
tests inside the existing worker describe, reusing dispatchPush/loadWorker):
1. `refuses a bare hostile kind: null payload, generic heading and tag` —
   dispatch {kind:'evil\u0001x'} only. Guard present: payloadOf → null → title
   = t generic 'CandyCode needs your attention', tag 'candy-code:generic'.
   Widened: kind survives → heading falls through to 'Turn finished' and tag
   becomes candy-code:evil\u0001x. Asserts title + exact tag + not-toContain
   'evil'.
2. `coerces a hostile kind beside a body to the question card` — dispatch
   {kind:'evil\u0001x', body:'a body', sessionId:'sess-7'}. Guard present:
   kind dropped, safe content shows → 'A question needs you' +
   'candy-code:question:sess-7'. Widened: 'Turn finished' + hostile tag.
Together the pair pins BOTH branches of the guard (null-payload fallthrough and
the `kind ?? 'question'` coercion), exactly the divergence the review reasoned.

Raw evidence (all from this fixer's vitest runs, cwd candy-code-web, absolute
node_modules/.bin/vitest, --no-file-parallelism, serial):
- Guard-present: `Test Files 1 passed (1)` / `Tests 22 passed (22)` (old 20 + new 2).
- Mutation (cp backup + md5 df9d74d5c42fd39cafbc8a9cdbedcfb9; :105 replaced with
  `typeof record.kind === 'string' ? record.kind : null`, MUTATION-APPLIED):
  `push.spec.ts (22 tests | 2 failed)` — ONLY the two new legs red:
  `AssertionError: expected 'Turn finished' to be 'CandyCode needs your attention'` (:452)
  `AssertionError: expected 'Turn finished' to be 'A question needs you'` (:466)
  `Test Files 1 failed (1)` / `Tests 2 failed | 20 passed (22)` ← all old pins green
  under the widened guard: the vacuity claim itself reproduced, and the new pins
  discriminate it.
- Restore: md5 re-checked df9d74d5c42fd39cafbc8a9cdbedcfb9 + cmp byte-identical
  (RESTORE-CMP-OK); re-run `Test Files 3 passed (3)` / `Tests 30 passed (30)`
  (push 22 + pwa + dormantRoster family).

## §3 §7 list for the welder (LIVE figures, never fixed here)
- vitest LIVE: push.spec.ts = 1 file / 22 tests (was 20). Adjacent re-run
  push+pwa+dormantRoster = 3 files / 30 tests, 0 failed.
- php side ZERO touched: this pick's --name-only = exactly
  candy-code-web/src-web/stores/__tests__/push.spec.ts. en.php zero new keys;
  no php test file moved → TreeWideGuardRosterTest / DuplicatedTestHelperDriftTest
  / CliLangCatalogue+LangParity gates UNOWED by this commit.
- durations.tsv: no new phpunit *Test.php → rows unchanged, none owed.
- Welder carries (per REVIEW-1 §7, still owed): cherry-pick THREE picks
  (02e18c14c, c8b76091b, c40dfd266) onto current master (3c9806eb1 era —
  master has MOVED; lane never rebased); web.yml dist parity rebuild + drift
  list (sw.js at root + hashed chunk renames, Q3); gen:strings LEAD rider
  untouched; ReadmeSuiteFigureDrift staleness pair disclosure (foreign carry);
  COMPARISON push-client cell candidate stands as review wrote it.
- REVIEW-1.md + REPORT.md byte-untouched by this lane (read-only usage).
- Commit law: c40dfd266caa2ac1635347e4b53319e431da74d1 (git cat-file -t =
  commit in the citing shell); %ae detain count 1, %ce detain count 1
  (separate greps), Co-Authored-By count 0; porcelain 0 at end.
