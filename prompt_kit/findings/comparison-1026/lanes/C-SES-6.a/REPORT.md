# C-SES-6.a — mapPhar fallback lets a renamed phar boot its own stub

LANDED: tip `f9a4c2d46` on `cc/C-SES-6.a` (base `e3a84187d`), worktree `/home/sites/cc-cmp-C-SES-6.a`. Author/committer Joe Huss <detain@interserver.net> (%ae/%ce separate greps, zero Co-Authored trailers).

## Fix
`candy-code/bin/candy-code`: between the two archive-identity doors and the candidate-replacement, a third door — when `$archive === ''`, `try { \Phar::mapPhar('candy-code.phar'); $archive = 'phar://candy-code.phar'; } catch (\PharException) {}`. mapPhar is its own oracle: it loads the archive sealed in the running script's bytes; on any unsealed script (composer checkout, guard-test copy) it throws and the three filesystem candidates stand byte-untouched.

## Gate results (cwd worktree candy-code, plain pipe, env -u LINES -u COLUMNS, `-c phpunit.xml`)
- RED-BEFORE `--filter 'BinCandyCodeAutoloadGuard|PharCompat|SuiteSkipRoster'`: Tests 78, Assertions 454, **Failures 2** — exactly (1) the guard restate arm (bin lacked the door) and (2) the renamed boot rc 2 + `cannot find composer autoload.php` (the C-SES-6.a defect reproduced live).
- GREEN-AFTER same filter: `OK, but there were issues! Tests: 78, Assertions: 467, PHPUnit Deprecations: 2, Skipped: 1` (skip = heavy arm, CANDY_CODE_PHAR unarmed; deprecations pre-existing house pair).
- Regression `--filter 'SelfUpdate'`: OK — Tests: 42, Assertions: 759 (+2 deprecations).
- Five-guard `ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift`: Tests 133, Assertions 15618, **Failures = staleness pair ONLY** (pinned 25,537 vs live 25,987 — the +450 gap is pre-existing base drift from foreign picks, my +2 methods ride it; figures are closeout duty, untouched).
- Adjacent `StderrEmitterCensus|TreeWideGuardRoster|SymbolCitationDrift|DuplicatedDocBlockLine`: Tests 125, **Failures 2 — foreign-inherited, NOT this lane**: both name `Cli/SelfUpdateTest.php => glob($phar.'.tmp-'.\chr(42))` unaccounted in the walker roster; `git diff e3a84187d HEAD -- tests/Cli/SelfUpdateTest.php` and the guard file are EMPTY/byte-identical to base, so the red predates this commit (some earlier weld left the roster row unminted). OWED to whoever owns that file / the gate.

## Phar::running(false)-under-alias probe truth (PHP 8.3.6, live-measured)
In STUB top-level frames `Phar::running(false)` answers '' (both suffix and suffix-less). In code REQUIRED from inside the archive — where SelfUpdate::run, Help::version and every shipped reader actually execute — under `Phar::mapPhar('candy-code.phar')` it answers THE REAL DISK PATH of the renamed script (e.g. `/tmp/…/candy-code`), not the alias. **SelfUpdate ($pharPath ?? Phar::running(false)) therefore swaps the file actually launched — benign under this fix, no change made.** Pinned behaviorally: the mini arm's marker prints `MINI-BOOT:` + Phar::running(false) from inside and the test asserts stdout `=== 'MINI-BOOT:' . $renamed . "\n"` (and the suffix control likewise).

## Files (numstat)
- candy-code/bin/candy-code +22/−0
- candy-code/README.md +5/−1 (Install phar paragraph: renaming to a suffix-less executable and symlinking both boot the archive's own vendor, stray vendor never consulted — §1.8 same commit)
- candy-code/tests/Integration/BinCandyCodeAutoloadGuardTest.php +53/−6 (docblock restates the third door incl. why mapPhar's PharException keeps the exit-2 negative control byte-true; new `testTheStubCarriesTheMapPharFallbackPairedWithTheBuildAlias` pins the executable statements AND the alias pairing with `tools/build-phar.php`'s `new Phar($outArg, 0, 'candy-code.phar')` — drift on either side reddens)
- candy-code/tests/Integration/PharCompatTest.php +107/−0 (new default-suite mini arm: real bin bytes as stub, alias `candy-code.phar`, marker vendor, renamed copy + suffix control + POSIX symlink leg asserting `MINI-BOOT:<real path>`; heavy arm gains suffix-less release-copy `--version` leg asserting the identical stamp banner; class docblock gains the C-SES-6.a law paragraph)
- candy-code/tests/Support/SuiteSkipRoster.php +22/−3, candy-code/tests/SuiteSkipRosterTest.php +29/−10 (PHAR_RENAME key riding pharWritingPossible() with the premise; roster gates/sims/messages moved in-step per §1.8)
Total: 6 files, +238/−20.

## Deviations / disclosures
1. **Scope grew by two files (test-infra coupling):** the brief's file list omitted `tests/Support/SuiteSkipRoster.php` + `tests/SuiteSkipRosterTest.php`; the mint-gated default-suite arm the brief mandates requires a roster key (an off-roster markTestSkipped reddens the skip guard — that guard IS its own machinery), and §1.8's in-step guard law decides: guards move in the same commit. All edits are the roster's own pattern, zero src surface.
2. **Brief's gate shape was vacuous:** `defined('__COMPILER_HALT_OFFSET__')` answers false inside the halting file (compile-time substitution; measured both primary and included contexts). Chose mapPhar-itself-as-oracle via PharException; rationale recorded in the bin comment and pinned by the negative-control arms.
3. **Heavy suffix-less leg armed with real archive not run in-lane:** linked monorepo vendor refuses local builds even under `--vendor=monorepo` (guard-4 symlink refusal), and the only on-box archives (`/tmp/opencode/w2j/*.phar`) carry the OLD stub bytes — arming them would test nothing. The leg rides the release workflow's CANDY_CODE_PHAR arm; the mini arm proves the identical door on the identical stub bytes.
4. **SelfUpdate:** untouched, per ruling; probe truth above shows it benign. README is the only docs surface (serialized [headless][docs:README.md] honored; ReadmeJsonErrorContract/ReadmeRoster guards green in the Readme filter).
5. Durations: **none** — no new test files (both arms extended existing files).

---

# Round-2 (fixer, 2026-10-27) — REVIEW-1 single [MINOR] taken

FIX (one commit `72b91a01d` on tip `f9a4c2d46`, comment-only): the bin
`__COMPILER_HALT_OFFSET__` parenthetical was replaced with the measured truth
and the mapPhar-as-oracle justification no longer rests on a bare
"cannot gate" vacuity claim. Behavior diff between f9a4c2d46 and HEAD: zero
non-comment lines (`git diff | grep -E '^[+-]' | grep -cvE '^[+-]\s*//' == 0`).

PROBE (transcript: this dir's PROBE.md): `defined()` answers FALSE in every
context — halting file, real phar stub (suffix and renamed launches),
truncated archive's stub, inside-archive frame, plain script — so the
original claim's defined() half was TRUE, now with evidence; but the bare
token/`constant()` DO resolve inside a halting file (php.net's "defined"
shape), so "cannot gate" over-reached. Measured refutation of a halt-gate:
the token answers int(163) in a TRUNCATED archive whose bytes no longer
load — a halt-gate waves through exactly the broken installs; and on a real
bin-byte stub the truncated renamed install falls through mapPhar's
PharException to rc 2 with stderr byte-exact `candy-code: cannot find
composer autoload.php\n` (the line BinCandyCodeAutoloadGuardTest pins at
:348/:391 for unsealed scripts). The new comment states all of this and
points to this PROBE.md as its transcript.

GATES (cwd worktree candy-code, plain pipe, env -u LINES -u COLUMNS):
- `--filter 'BinCandyCodeAutoloadGuard|PharCompat|SuiteSkipRoster'`:
  78T/467A/1S — byte-exact vs Round-1, figure unmoved (1S = heavy arm,
  CANDY_CODE_PHAR unarmed; brief's filter string 'PharCompat|BinCandyCode'
  as written additionally matches BinCandyCodeWiring/Dispatch/TuiErrorLog —
  ran that full shape too: 1348T/8064A/1S, fully green, no bin-byte fallout).
- five-guard: 133T/15618A/2F = staleness pair ONLY (25,537 pinned vs 25,987
  live — pre-existing base drift, identical to Round-1's run).
- php -l bin/candy-code clean.

Author law: %ae and %ce separate greps each = 1 detain@interserver.net;
Co-Authored-By count 0. Tree clean. Not pushed; suite-figure.json/durations
untouched; no new test files. Reviewer's disclosure-2 wording ("gate shape
was vacuous") is superseded by the precise form above: the defined()-gate
was vacuous, the token-gate was expressible but wrong-oracoed.
