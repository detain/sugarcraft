# C-SES-6.a — REVIEW-1 (fresh round-1 reviewer, 2026-10-27)

VERDICT: FINDINGS

- [MINOR step-local] candy-code/bin/candy-code:43-46 + REPORT disclosure 2 — the
  gate-substitution rests on "defined('__COMPILER_HALT_OFFSET__') answers false
  inside the halting file — measured", but no probe transcript exists in the lane
  evidence dir, and reviewer php execution is denied (could not reproduce). The
  claim is contestable: php.net presents the constant as defined for the halting
  file, and the same file's own stub build appends __HALT_COMPILER. Behavior is
  safe either way (all four shapes + composer fall-through are pinned by tests),
  so this is comment-truth only: fix = drop a probe transcript (halting file +
  var_dump(defined(...)), primary and included contexts) under
  prompt_kit/findings/comparison-1026/lanes/C-SES-6.a/, or reword the comment to
  justify mapPhar-as-oracle without asserting a vacuity measurement.

## Clean axes (all verified against disk)
1. Scope: exactly the 6 claimed files, +238/-20; [headless][docs:README.md]
   honored; the +2 roster files are MANDATED by §1.8 in-step guard law (new
   skip key moves same commit as the mint-gated arm), not a scope breach.
2. Door logic hand-traced all four shapes: source/composer (mapPhar throws
   PharException -> 3 candidates byte-standing; exit-2 negative controls with
   EXACT stderr equality at guard-test :348/:391 would catch any warning leak),
   canonical .phar (door 2 unchanged), renamed-suffix (door 2), suffix-less
   copy (door 3 -> alias). Truncated/corrupt phar: signature miss throws ->
   clean exit 2; entry-missing -> is_file false -> exit 2. No confusing fatal.
3. Alias pairing live on disk: build-phar.php:265 `new Phar($outArg, 0,
   'candy-code.phar')` matches the stub literal; both sides source-pinned.
4. Tests non-vacuous: red-before 2F composition is coherent with base code
   (door absent => restate arms false + renamed boot rc2). Mini arm uses REAL
   bin bytes as stub, asserts exact MINI-BOOT:<real path> stdout and EMPTY
   stderr, suffix control + POSIX symlink leg. Skip env-gated via
   pharWritingPossible() + SuiteSkipRoster PHAR_RENAME key, not silent.
5. SelfUpdate:358 + Help::hostArchive:187 read Phar::running(false) only from
   inside-archive frames; mini-leg equality pins the real-disk-path answer ->
   self-update swaps the file actually launched; no alias-equality assumption.
   Untouched per brief risk note — correct.
6. SuiteSkipRosterTest moves both directions in-step (membership parity leg,
   recordPrepared/recordSkip both branches, skip count +=2).
7. README Install paragraph truthful against the door as read.
8. §1.9: no glob-shaped literals, no Refused-shaped fixtures, per-process
   uniqid-style scratch dirs; E646: zero timers; no proc_open additions;
   no new *Test.php files -> durations claim consistent.
9. Gates NOT re-run by this reviewer (php/vendor-bin execution denied in the
   review shell); builder figures (78T/467A/1S, SelfUpdate 42T OK, staleness
   pair-only, foreign TreeWideGuardRoster glob pair base-attributed) accepted
   as claims — the foreign pair's zero-diff attribution verified on disk
   (tests/Cli/SelfUpdateTest.php absent from the lane diff).

Ruling: single MINOR, step-local, fix-now genre (artifact or one comment
sentence). No behavioral change requested. 0 CRITICAL / 0 MAJOR.
