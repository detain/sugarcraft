# C-SES-8.c — REVIEW-2 (round 2, fresh reviewer)

**VERDICT: CLEAN** — 0 CRITICAL / 0 MAJOR / 0 MINOR / 0 NIT. All three round-1 items fixed on tip `cc8ea765f`; no scope creep; every claimed figure re-derived EXACT live.

## Tip integrity
`cc/C-SES-8.c` @ cc8ea765f (parent e2759d081, base cf0cfd0be — 2 commits). `git show --stat cc8ea765f` = exactly 3 paths: `src/Host/Commands/InsightsHostCommand.php` (+12/-3), `tests/Host/Commands/InsightsHostCommandTest.php` (+49), `lang/en.php` (1-line swap-pair). Union over both commits = the brief's 5 files, nothing else. `%ae`/`%ce` both detain@interserver.net on both commits; Co-Authored trailer grep = 0. No suite-figure.json/durations.tsv/dist/PathGlob in any diff.

## r1 item 1 (MINOR) — FIXED, verified structurally + behaviorally
Diff confirms `$directory`/`$stamp`/`$target` resolution moved INSIDE the try (with why-comment). Success return after the try dereferences `$target` only on the non-throw path (catch returns). New pin `testAShelfThatCannotBeLocatedIsAWriteFailureNotAThrownPageLoss`: default-ctor command, HOME=/ both spellings (env + `$_SERVER`), finally restores both (OneSided-compliant; SuiteHomeSandboxContract green); asserts page start + 'Report not written:' + 'nowhere to land' + absence of 'HTML report:'. Pre-fix red is mechanically certain (resolver throw escaped `run()` → 1E); post-fix green measured in the 15T run. 'nowhere to land' is the resolver's own RuntimeException message at src :106 — the pin provably exercises the refusal arm, not a generic IO failure. en.php `write-failed`/`written` spellings ('Report not written: {reason}', 'HTML report: {path}') match the asserts (en.php:1346-1347).

## r1 item 2 (NIT) — FIXED
`spend-day` before `spend-heading` (en.php:1336-1337). Whole 34-key block (1314-1347) re-verified byte-alphabetical by hand over the adjacent-pair list (`-` 0x2D < letters; `col.*` < `cost`; `no-store` < `none`; `write-failed` < `written`). (Reviewer's first `sort -c` false-alarm was locale punctuation-ignoring collation; C-order is the block's law and holds.) No `cmd.*` sort guard exists, so §1.8 is untouched — LangParity covers the key set, and it's green.

## r1 item 3 (NIT) — figures re-stamped, all EXACT at tip (cwd=candy-code, plain pipe, env -u LINES -u COLUMNS)
- `--filter 'InsightsCommandTest|InsightsHostCommandTest'` = **15T/84A** OK ✓ (14 + new pin)
- `--filter 'Insights|Usage'` = **405T/2047A** OK ✓
- guard trio `StderrEmitterCensus|TreeWideGuardRoster|DocFigureProseDrift` = **204T/29126A** OK ✓
- `LangParity` = **7T/3025A** ✓; `GlobDialectDifferential|DuplicatedTestHelperDrift|SuiteHomeSandboxContract` = **32T/594A** ✓ (PathGlob corpus stable)
- 2 PHPUnit deprecations = pre-existing AccessorDeprecationTest private-provider house noise (round-1 provenance holds).

## Privacy doors / laws — PASS (unchanged from r1 plus fix)
0600 via AtomicFileWriter still the sole write call; resolution-failure writes nothing; junk window → usage reply, no store → no file (r1-pinned arms byte-untouched by the fix hunks); zero model/network/timer sites (E646 clean); dormancy-by-design (C-SES-8.d owns wiring per brief docs/guards).

## Disclosure audit — CONFIRMED
`OneSidedHomeSandboxTest::testNoTestFileSandboxesOnlyHalfOfHome` red at tip accuses foreign `Context/InstructionWalkPastRootTest.php` (+ pre-existing roster drift rows); failure output greps 0 for 'insights' — the lane's new pin is NOT accused. Accusation object exists at base cf0cfd0be; `d361720d9` (C-CTX-4.c) verified as a commit AND an ancestor of the base → red is base-identical, foreign-attributed. Route to C-CTX-4.c owner at weld.

## For the welder
- durations.tsv: 0 rows for `candy-code/tests/Commands/InsightsCommandTest.php` + `candy-code/tests/Host/Commands/InsightsHostCommandTest.php` (verified via grep at tip) — mint both rows in the wave gate.
- suite-figure.json/README carry: **+15T** (updated from +14T; final tip cc8ea765f).
- plan §4 mark: `✅ cc8ea765f C-SES-8.c` (cite cc8ea765f, not e2759d081).
- Foreign OneSided roster row (InstructionWalkPastRootTest) → C-CTX-4.c owner route, NOT this lane's.

Reviewer: cc-cmp round-2, 2026-10-28. Read-only; no repo file touched by this review.
