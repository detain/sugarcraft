# W36 — loop fix round 1 (M-1: StderrEmitterCensus red at master)

Fix commit (cat-file-verified this shell, `git cat-file -t` = commit, full SHA):
`cd3d1aecb90506179aff37c79ecab2f72fce3e1a` ("W36 wave-review: roster PtyRelay's 9 stderr emitters …").
Author law: %ae grep-c detain = 1 and %ce grep-c detain = 1 (SEPARATE greps), Co-Authored-By = 0. Explicit-path staging, two files only.
This report rides the parent fix commit as a follow-up evidence stamp per the house no-self-SHA law (precedents c082a64/e27787c/8e8f790).

## Roster diff summary (tests-only; zero behavior change; PtyRelay.php untouched)
- `candy-code/tests/Cli/StderrEmitterCensusTest.php` (+16/−1):
  - `DIRECT_SITES` gains `'src/Background/PtyRelay.php' => 9` between `bin/candy-code` and
    `src/Cli/Acp.php` (the scan's key order), with a decision comment in the existing row shape:
    stderr-alone by the EgressBridge detached-process decision — the relay IS the child, its
    stderr is captured to the session's `<id>.err` sidecar, no live transcript on that path.
  - Header channel-1 prose `thirty-eight sites` → `forty-seven sites`, C-TOOL-6.j 2026-10-31 +9
    clause prepended (reverse-chronological ledger style, matching the C-TOOL-4.g bump precedent).
    The prose is LOAD-BEARING: `selfCountAnchors()` row 1 anchors the word against
    `array_sum(DIRECT_SITES)`.
- `candy-code/tests/Integration/BinCandyCodeAutoloadGuardTest.php` (+1/−1): doc-block numeral
  `call sites across `src/` and `bin/` is THIRTY-EIGHT` → `FORTY-SEVEN` — the exact sentence
  `testTheInheritedCensusStillAgreesWithTheScan()` anchors (regex `is ([A-Z]+(?:-[A-Z]+)?)`,
  word map carries `forty-seven` => 47). Per-file enumeration NOT extended: the C-TOOL-4.g
  bump (df106a84c) set the precedent of moving the numeral in place only.

## Reviewer-paraphrase deviations (re-derived from live test output per brief)
- The brief's "~:60 / :131 / :140" prose sites: only the :60 channel-1 sentence moved. :131
  ("the thirty-eight its earlier revisions named") and :140 ("thirty-eight to thirty-four")
  are HISTORICAL channel-3 error_log roster figures, not the fwrite census — changing them
  would falsify history. The :55 quote "is NINETEEN" is the header's historical quotation of
  the guard sentence as of its authoring day; it is inert to the :2485 regex (which scans the
  guard file only) and was already stale before W36 — left.
- The on-disk WAVE-REVIEW-1.md verdict line reads CLEAN (0M); the dispatch cited C=0 M=1.
  Disk adjudicated: the two census failures reproduced verbatim at HEAD a48287d9a before any
  edit, so the fix was cut. Foreign ReadPathCensus MediaMethods + RuntimeTest idle-compaction
  reds NOT touched (standing other-wave carries). suite-figure.json / durations.tsv NOT touched
  (§7 battery carry).

## Gates (cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe, post-fix)
- `--filter 'StderrEmitterCensusTest'` → OK 95T / 24,037A / 0F / 0E (Deprecations: 2 base shape).
- `--filter 'BinCandyCodeAutoloadGuard'` → OK 38T / 93A / 0F / 0E.
- `--filter '(TreeWideGuardRosterTest|DuplicatedTestHelperDriftTest)::'` → OK 31T / 4,211A / 0F
  — byte-exact vs the review's §5 BARE figure.
