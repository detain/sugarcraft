# C-TUI-2.b — REVIEW-1 (round 1, reviewer of lane cc/C-TUI-2.b @ d3d5e7825)

VERDICT: CLEAN

## Phantom-first
- `git cat-file -t d3d5e7825...` = commit; `master..HEAD` = exactly 1 commit (parent == merge-base == 55d66b158, the claimed base); porcelain 0 lines (clean tree).
- `git show --name-only`: exactly the 4 claimed files, all under sugar-diff/ — zero candy-code touches, no durations.tsv / suite-figure / dist / plan-file marks (lib-local lane, correct).
- `%ae` and `%ce` each = detain@interserver.net (separate format fields); 0 Co-Authored trailers in `%B`.

## Design conformance (plan :1832-1841, design-tui.md :154-168)
- rows(Diff) + fromUnified(string) over Reader\UnifiedScan, both returning paired [left?, right?] rows — shipped as specified; pure data (no widths/paint), private-ctor final statics mirroring UnifiedFormat's shape.
- Pairing rule matches design: removals queue, addition takes oldest queued partner (FIFO array_shift), leftovers stand alone both directions, context pairs with itself (same immutable Line both sides — pinned identity at test :94), no pairing across hunk/@@ boundaries (rows() flushes per hunk; fromUnified flushes on every unnumbered row except the `\` annotation).
- Annotation semantics (M1 category): `\ No newline` is skipped INSIDE a run, not a boundary (SideBySideFormat.php:98). Verified load-bearing by structure: UnifiedFormat emits the annotation right after the del row of an unterminated-old-side replacement (renderHunk :74-76 + sitsAtUnterminatedEof closesOld), so treating it as a boundary would split every EOF replacement run.
- fromUnified kinds come from the scanner's counters, not marker re-derivation (:105-107) — matches the "read from the scan" design and keeps SQL-shaped rows (`--- drop table` as a real del row) correct under the scanner's documented in-hunk content rule.

## Deviation judgment (both DISCLOSED, accepted)
1. `SideBySideRow::isPaired()` — additive pure-data accessor (`left !== null && right !== null`), zero behavior, bare-accessor naming (no get*). Fine.
2. rows() walks hunk bodies (context-grouped) rather than raw Diff->lines(). This is the correct choice for the claimed 1:1 parity: unified output emits hunk bodies including context rows, and hunk-body walking gives an explicit per-hunk flush so the @@ boundary can never pair across hunks even when the raw op sequence would. Re-reasoned the 18-fixture battery: uneven-left ([['a','a'],['b','x'],['c',null]] right-boundary orphan del), uneven-right ([null,'y'],[null,'z'] orphan ins tail), U0 two-hunk boundary test (:101-116 asserts BOTH paths + asserts the fixture really is 2 hunks), U0 EOF replace, devNull add/remove, blank/empty context rows, SQL `--`/`++` shaped removals, `@@`-shaped content, unterminated variants, CRLF — coverage claim honest; each provider row additionally runs through the vacuity arm (assertNotSame([],rows) + every row carries ≥1 side), so a dead reader path cannot pass parity vacuously.

## Mutation table — reasoned by inspection (cp of repo src denied by reviewer permission map; no repo file touched)
- M2 (context does not close the run): the committed pin testRunsSeparatedByContextDoNotPairAcrossIt (:74-86) asserts ABSOLUTE shape [['a',null],['b','b'],[null,'X']] first, then cross-path parity. Under a flush-neuter both paths break identically (parity leg stays green — correctly not relied on), and the shape leg dies → exactly 1 red. Builder's story (initially GREEN gap → pin closed it) is consistent with the code: the del→context→ins fixture is the only shape-pinned non-adjacent-run case; the other tests have no pending-across-context. CONFIRMED non-vacuous.
- M1 (`\` treated as boundary): dies on testNoNewlineAnnotationDoesNotSplitAPairingRun + parity row 'unterminated both ends' (annotation lands between `-del` and `+add` — GNU shape verified in UnifiedFormat). 'unterminated old side only' lands the annotation AFTER an already-flushing context row (inert under M1, confirmed via sitsAtUnterminatedEof closesOld on the eq row) → builder's "2 reds" is exactly right by my enumeration.

## Gates reproduced (plain pipe, env -u LINES -u COLUMNS, cwd=sugar-diff)
- `vendor/bin/phpunit` → **OK (116 tests, 339 assertions)** — EXACT vs claim.
- `--filter SideBySideFormat` → OK (46 tests, 114 assertions) = 10 explicit + 18×2 provider rows (claim's "113" prose slip, +1 assertion from a later edit; totals match on disk).
- `php -l` × 3 new files: clean.
- `tools/check-one-type-per-file.php` + `tools/check-path-repos.php`: invocation DENIED by reviewer shell map (`php tools/*` not allowed). Verified equivalently: each new src file declares exactly 1 top-level type (grep census = 1 each; full-file read confirms no second type), and the diff contains no composer.json touch, so the path-repo guard cannot regress.

## Laws
- E646 N/A: pure formatter, zero timers/wall-clocks. Immutability: readonly promoted props, no mutable state, no with* needed (no options carried). declare(strict_types=1) first line both files; final everywhere; docblocks cite Mirrors/upstream + GAP-UI-05 and explain WHY (fail-closed posture, annotation-not-boundary rationale). No user-facing strings → Lang N/A. Test literals: no glob-shaped T_ENCAPSED hazards; sugar-diff is not in the candy-code corpus walk anyway.

## Queued NITs (no fix required this round)
- NIT: REPORT.md says test file "46 tests / 113 assertions"; committed file measures 114 (total suite claim exact). Report prose only.
- NIT: README API row lists `rows()`/`fromUnified()` only; `SideBySideRow::isPaired()` is undocumented in the table — consistent with sibling rows that enumerate headline methods only.

VERDICT: CLEAN
