# C-TUI-6.a — FIX-1 (round 1 fixer)

Lane: /home/sites/cc-cmp-C-TUI-6.a, branch cc/C-TUI-6.a, base b45117223.
Picks 1/2 as reviewed: 6db0d00cb (src layer), 1efe40828 (pins) — byte-untouched (never amended).
Fix pick 3: 8f8a7dde0 "C-TUI-6.a: review fixes (round 1)" — 3 files +33/−2:
candy-code/src/Tools/BuiltIn/Bash.php, candy-code/src/Support/PosixShellQuote.php,
candy-code/tests/Support/PosixShellQuoteTest.php. Law: %ae=1, %ce=1 (SEPARATE greps),
Co-Authored 0, residue staged-hunks 0, porcelain 0 after commit.

## §1 MAJOR-1 — Bash.php:535 double-quote restore (CURED)

Exact prescription applied:
`$cmd = 'bash -c ' . PosixShellQuote::quote($script);` → `$cmd = "bash -c " . PosixShellQuote::quote($script);`
ONE line; :512 comment kept as-is per review.

Zero-behavior-change proof: /tmp/opencode/cc-tui6a/lit-equiv.php ran `"bash -c " === 'bash -c '` →
bool(true) and a 519-payload concat fuzz (empty, quotes, `%`/`!`/`"`, tab/newline, high-bit bytes,
CJK, every single byte chr(1)..chr(255), embedded-quote combos) → tries=519 fails=0. The
concatenation result is byte-identical to the previous form for any input.

RED-BEFORE (this fixer's own run, tip 1efe40828, class anchor, cwd=candy-code):
  `--filter 'PermissionGateScopedWriteTest::'` →
  `Tests: 78, Assertions: 85, Failures: 1, PHPUnit Deprecations: 2.`
  named failure = testTheBashToolStillSpawnsThroughBashNotSh, PermissionGateScopedWriteTest.php:479
  (needle `"bash -c "` absent from the single-quoted source — the r1 documented 1F, reproduced).

GREEN-AFTER (same command at fixed tree):
  `Tests: 78, Assertions: 85, PHPUnit Deprecations: 2.` → OK (Failures: 0).
  (The 2 PHPUnit Deprecations are ambient and appeared in EVERY run before and after, including
  all gate lines below — not lane content.)

MERGE-CLEAN PROOF — Bash-substring era spot (`--filter 'Bash'`):
  r1: `Tests: 532, Assertions: 2337, Failures: 1` → this run: `Tests: 532, Assertions: 2337, OK`
  (Failures: 0; exact same T/A counts with the one MAJOR-1 red gone).

## §2 MINOR-1 — PosixShellQuote byte-identity truth (CURED)

Docblock truth sentences (both claim sites cured):
- class docblock: new paragraph naming the divergence class verbatim — raw non-UTF-8 8-bit bytes
  (chr(128)..chr(255)) ride verbatim where a UTF-8-locale escapeshellarg STRIPS them; keeping is
  the safe receiver-correct direction (single quotes, bash treats non-`'` bytes literally; a strip
  would change the command, a verbatim byte cannot expand or inject).
- quote() method docblock: "for every input it accepts" claim now states the one accepted
  divergence class, pointing at the class block.

Corpus row: PosixShellQuoteTest::testHighBitNonUtf8BytesRideVerbatimTheAcceptedDivergenceClass —
CANNOT join quoteCorpus() (equality vs the live oracle is exactly what fails for this class), so
the row documents its own expected bytes: short sample chr(128).chr(170).chr(255) + full
chr(128)..chr(255) sweep, both assertSame against quote().

Figures (mine rule): PosixShellQuoteTest:: alone → `Tests: 30, Assertions: 34` (r1 29/32 + 1T/2A).
Five-file family → `Tests: 64, Assertions: 141` (r1 63/139 + corpus leg).

DISCRIMINATING RECEIPT (EXEC-forced, not static-only): cp-backup + temporary
`preg_replace('/[\x80-\xFF]/', '', $argument)` strip branch inside quote() →
`--filter 'testHighBitNonUtf8BytesRideVerbatimTheAcceptedDivergenceClass'` →
`Tests: 1, Assertions: 1, Failures: 1` at PosixShellQuoteTest.php:140. Restored; md5
b943d1e7dc5df60ffd2a88135e75c24e verified equal pre/post. The leg fails the moment quote()
ever grows a strip branch.

## §3 NIT dispositions

- NIT-1: CORRECTION RECORDED HERE ONLY — the builder REPORT.md sentence "seams absent from my
  diff" should read "no seam CODE touched": the diff carries 2 prose mentions (1 context comment
  line `interactiveSpawnCommand() since F-E5`, 1 added comment `ProcessContainment::
  interactiveAvailable() refuses Windows`); seam code (interactiveAvailable/interactiveSpawnCommand/
  startInteractive) genuinely untouched. REPORT.md byte-untouched (history).
- NIT-2: reviewer-harness self-inflicted concurrent-filter red; ledger note only, zero lane action
  (reviewer's own 4/4 sequential re-runs green at 29T/228A for BackgroundShellRegistryTest).

## §4 §7 RESTATEMENT for the welder (fixer live figures rule)

durations.tsv rows for the FIVE new test files (closeout mints; fixer never touches the file):
  PosixShellQuoteTest 30T/34A  (RESTATED — was 29/32 at r1; +1 corpus leg)
  WindowsShellTest 13T/26A     (unchanged)
  WindowsTaskKillTest 13T/50A  (unchanged)
  BashWindowsSpecTest 5T/14A   (unchanged)
  BackgroundShellWindowsArmTest 3T/17A (unchanged)
Five-file family combined: 64T/141A. Suite-pair carry from the lane: +63T class at r1, now +64T
class — battery re-pin owns the pinned pair (25,537T/609,443A NEVER edited here).
en.php / protocol / web strings: ZERO diff (name-diff count 0) → CliLangCatalogueTest|LangParityTest
UNOWED. BARE TreeWideGuardRosterTest → `Tests: 17, Assertions: 3949` OK. BARE
DuplicatedTestHelperDriftTest → `Tests: 14, Assertions: 204` OK. GlobDialectDifferentialTest →
same single foreign 1F shape (asserted shape, not numbers; added code lines carry ZERO wildcard
chars — scan proof `ZERO-wildcard-in-added-code-lines`).
Welder WARN unchanged: master moves (C-TOOL-6.g weld may land first) — re-derive the
ProcessContainment/Bash/CapturesProcessOutput/BackgroundShellRegistry hunks by TEXT; Bash.php:535
in the fix-pick now reads the DOUBLE-quoted form — a 6.g-adjacent re-derivation must preserve it
(PermissionGateScopedWriteTest:479 pins it).
WELD-ELIGIBLE: YES (MAJOR-1 cured with red-before/green-after receipts; MINOR-1 cured; NITs
dispositioned).
