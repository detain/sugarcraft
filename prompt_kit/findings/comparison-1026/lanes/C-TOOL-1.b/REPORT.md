# C-TOOL-1.b — rg `--no-config` jail fix — CLOSED

- Tip (re-read from `git log`, branch `cc/C-TOOL-1.b`, base d7bbd104d):
  `3fbbe5948394f4b0647e883989572aa40f76ce4c C-TOOL-1.b: rg respects --no-config; inherited RIPGREP_CONFIG_PATH cannot widen the walk`
- Author/committer: `%ae` and `%ce` each grep-detain to 1; zero Co-Authored-By trailers; `git status --porcelain` empty.

## What shipped (numstat)

- `candy-code/src/Tools/BuiltIn/Grep.php` +22/−2 — `--no-config` as the FIRST rg
  flag in the command build; `['RIPGREP_CONFIG_PATH' => '']` env override on the
  single `runCaptured` spawn (empty == rg's own no-config reading, measured on
  14.1.0; scrubbedEnv overrides merge last so blanking is the unset the channel
  can express); buildCommand docblock truth-flip names the config-file vector
  (`--follow` out of band + `--smart-case`/`--max-columns`/`--type-not` parity drift).
- `candy-code/tests/Tools/BuiltIn/GrepOptionInjectionTest.php` +83/−1 —
  `testRipgrepConfigFileCannotWidenTheWalk` (temp rc holding `--follow` via putenv,
  jail with symlink to outside, real tool spawn, assert no hit; early-returns,
  never skips, on rg-less hosts — differential legs' convention; finally-restores
  the env var) + argv pin `testTheBuiltRgCommandCarriesNoConfigAsItsFirstFlag`
  (no-spawn reflection, helper named `builtRgCommandLine` to stay clear of
  DuplicatedTestHelperDrift) + class-docblock truth sentence.
- `candy-code/tests/Tools/BuiltIn/GrepEngineDifferentialTest.php` +1/−1 —
  in-step flip of the exact `rg --no-heading…` startsWith pin (guard moves with
  the fix).

## Reproduce-red

Both new tests red pre-fix through the REAL spawn path (leak output contained
`innocent_link/creds.txt:1:SECRET_TOKEN=hunter2`; argv pin named the flagless
line), green post-fix.

## Gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS)

- `--filter 'Grep'`: **222 tests, 134,688 assertions, 0F/0E** ("OK, but there
  were issues!" = 2 PHPUnit deprecations, base-proven pre-existing via stash —
  foreign, deprecations do not gate).
- Five-guard filter: **133T/15,349A, failures = ONLY the ReadmeSuiteFigureDrift
  staleness pair** — pinned 25,537 vs live 25,677 (lane carries +2T; the pair is
  weld/gate re-pin business).

## Deviations / notes

- No new test files — GrepOptionInjectionTest was MODIFIED; durations.tsv clean
  (nothing for the gate to add).
- No docs/guard rows: the tool description enumerates no rg flags (brief agrees).
- Not pushed; no caliber; suite-figure.json/durations.tsv untouched.

## Fixes (round 2)

- [MINOR] step-local (Grep.php:424 belt unpinned) FIXED. New structural
  source-slice pin `testTheRgSpawnCarriesTheEnvBlankingOverrideIntoRunCaptured`
  in GrepOptionInjectionTest.php: reads the single `$this->runCaptured(` call
  site out of the Grep.php source (call-site-count staleness arm + slice to
  the terminating `);\n`) and requires
  `'RIPGREP_CONFIG_PATH' => ''` to ride INSIDE the call. Prose elsewhere
  cannot satisfy it (needle is the exact quoted key + arrow + empty value).
- Mutation proof (honest shape, realistic belt removal): replacing the
  override with `[]` (the parameter default — the crude arg-deletion shifts
  $heartbeat into `array $envOverrides` and TypeErrors, not a representable
  regression) reddens the new pin (failure names the override-less slice)
  while `testRipgrepConfigFileCannotWidenTheWalk` and the argv pin stay
  GREEN (2T/5A OK) — exactly the reviewer's gap, now closed. Restored
  byte-exact via cp+md5 (492dc072… == orig), git diff shows only the test file.
- Gates (cwd candy-code, plain pipe, -u LINES -u COLUMNS): --filter 'Grep'
  **223T/134,694A** (= round-1 222/134,688 + my 1T/6A, 0F/0E, same 2
  base-proven deprecations); five-guard filter **133T/15,349A, failures =
  ONLY the ReadmeSuiteFigureDrift staleness pair** (weld re-pin carries +1T).
- Commit `86635cf8b5cd0851074f3a94a2bff921bf44859d` on cc/C-TOOL-1.b
  ("C-TOOL-1.b: review fixes (round 2)"), %ae/%ce each grep-detain=1, zero
  Co-Authored-By, tree clean. Existing test file → no durations duty; no
  suite-figure/README/caliber touches; not pushed. [NIT] DetectsCapabilities
  probe left untouched per ruling (out-of-scope, report-only).

CURE 2026-10-27 (weld gate): TreeWideGuardRosterTest red from this lane's r2 anchor
(:249 Grep.php source read) rostered in 30d4f91cb; Grep filter 223T/134,694A green,
remaining roster Fs are the disclosed foreign Cli/SelfUpdateTest globs only.
