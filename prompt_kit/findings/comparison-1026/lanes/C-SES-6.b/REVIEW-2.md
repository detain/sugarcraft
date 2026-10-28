# C-SES-6.b — Review Round 2 (fix verification)

VERDICT: CLEAN (delta check; r1 already APPROVE'd the body at 86185f0eb)

Scope: bb5cacc06 fix delta + targeted regression sweep. READ-ONLY on repo; no tree
mutations performed (reviewer map denies cp/mkdir/checkout — disclosed below).
DISCLOSURE: the intended path prompt_kit/findings/comparison-1026/lanes/C-SES-6.b/REVIEW-2.md
was denied by this session's edit-permission map; written to the sanctioned fallback.

## 1. Fix-commit hygiene — PASS
- `git show bb5cacc06 --stat`: exactly 1 file, `candy-code/tests/Integration/PharCompatTest.php`, +4/-0
  (comment :737-739 + `['SOURCE_DATE_EPOCH' => '']` frame :740). In the step block's declared
  modify-set. No dist/, no suite-figure.json, no durations.tsv, no other file.
- Author law: `%ae` = `%ce` = detain@interserver.net (separate greps, 1 each); 0 Co-Authored trailers.
- Working tree clean; 3 stash entries exist but are ALL foreign pre-existing lanes
  (cc/P-AGENT.b, cc/C-CTX-5.c, master local-wip-before-merge) — none on cc/C-SES-6.b, none by this fix.

## 2. Scrub correctness + non-vacuity — PASS
- Merge order verified: `launch()` (:852-861) seeds `$env = $extra` and skips parent keys via
  `isset($extra[$key])` — `isset('') === true`, so the frame wins over an ambient value; `''`
  (not null) is exactly the right sentinel (a null frame would let the parent value flow).
- Gate semantics verified in `candy-code/tools/build-phar.php` :281-287/:345:
  `getenv()` false→'' ; `preg_match('/^\d+$/','') === 0` → `$epochTs = null`; consumers use STRICT
  `!== null`, so empty-string is treated as unset for BOTH built_at and the touch loop. Correct.
- Independent hostile re-run: `SOURCE_DATE_EPOCH=1700000000 phpunit --filter
  testTwoEpochPinnedBuildsAgreeAndEveryPackagedFileCarriesThePinnedMtime` → **OK (1T/49A)**:
  the fixed wall arm (:748, `assertSame 1234654290`) passes THROUGH the scrub — non-vacuous because
  the sibling unframed leg in the same file (below) goes RED under the same export, proving the
  parent value genuinely flows into unscrubbed children (builder's claimed ":744 red,
  1700000000 vs 1234654290" is the same mechanism; not re-run as a tree mutation by law —
  the live sibling-leg red + isset-merge trace establish load-bearingness).

## 3. Targeted gate — PASS
`env -u LINES -u COLUMNS vendor/bin/phpunit -c phpunit.xml --filter
'PharCompatTest|BinCandyCodeAutoloadGuardTest'` (cwd candy-code, plain pipe, tip):
`OK ... Tests: 52, Assertions: 422, ... Skipped: 1` — exact match to the r1 figure, delta 0T/0A
(S = CANDY_CODE_PHAR real-archive arm; the 2 PHPUnit deprecations ride the r1 shape).

## 4. Phantom/ancestry — PASS
`cat-file -e` true for bb5cacc06b0608ede62c0d1ee625120d8c03652f and 86185f0eb;
`merge-base --is-ancestor 86185f0eb bb5cacc06` confirmed; `git log cf0cfd0be..HEAD` = exactly the
two expected commits; branch = cc/C-SES-6.b.

## Out-of-scope MINOR (observation, NOT blocking; pre-existing at base cf0cfd0be)
`PharCompatTest::testTheBuildStampRoundTripsThroughTheReaderAndHonorsSourceDateEpoch` :627-632
has an UNFRAMED wall-clock launch whose `assertLessThan(120, abs(time()-strtotime(built_at)))`
went RED live under my hostile export ("Failed asserting that 93212361 is less than 120").
Verified at base: the leg AND the SOURCE_DATE_EPOCH built_at honoring both predate this step
(DST-1 era, base :583/:588 + base build-phar :248), so this step neither introduced nor widened it.
One-line cure (`['SOURCE_DATE_EPOCH' => '']` frame) available for a follow-up lane; latent CI
interaction since the release workflow legitimately exports the var.
