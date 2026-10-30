# W26 wave-loop fix round m2 (master-direct) — FINDINGS 1-2 CURED, FINDING 3 RIDER

Date: 2026-10-30. Base re-read at start: `c013d930df80a83ea3828d98a95ab2103e8c29c2`
(`git rev-parse HEAD`), master tip `c013d930d W29 wave-review: MAJOR-1 bare-sink count 2->3 ...`.
Sole master writer this tick. Targeted filters only; no full suite.

## Verdicts (live re-derivation first, per finding)

| # | Finding | Live at tip? | Proof |
|---|---|---|---|
| 1 | `RuntimeNoticeSinkDeliveryTest::testNoSourceFileCarriesStackedDocComments` red on master | **REAL RED** | `env -u LINES -u COLUMNS vendor/bin/phpunit -c phpunit.xml --filter RuntimeNoticeSinkDeliveryTest` → `Tests: 25, Assertions: 6319, Failures: 1`, the failure naming exactly four `file:line` pairs (expected `[]`) at `tests/Diagnostics/RuntimeNoticeSinkDeliveryTest.php:1385` |
| 2 | `output-style` missing from `SLASH_ONLY_SURVIVORS` | **REAL RED** | `--filter PaletteSupersetDispatchTest` → `Tests: 7, Assertions: 249, Failures: 1`; `testThePaletteIsTheSupersetAndTheSurvivorsAreExactlyTheJudgedSet` diff inserts `3 => 'output-style'` (derived set has 23 rows, roster 22) at `tests/Chat/PaletteSupersetDispatchTest.php:215` |
| 3 | evidence-only rider on `lanes/W26/WAVE-REVIEW-2.md` | applied as an appended dated rider; no history rewritten | see below |

MINOR-2 (prune claim) and the NIT heading-twins: **no action taken** — nothing pruned
per directive (fleet ruling is FOREIGN-KEPT for `cc/C-TOOL-6.a`), heading twins are §7 closeout.

## Finding 1 — the four sites, and who created each adjacency

Blame on the stranded (first) block of each pair, cross-checked with
`git log -S<needle>` for the adjacency partner:

| site | created by | wave/step | shape |
|---|---|---|---|
| `tests/Chat/McpLoginForkTest.php:309` | `827f20e5c` | W27 / C-MCP-4.c | lane's paste-back rationale block sits directly above the private helper's own `@return` block — the tag still attaches, only the prose is dropped (cheap variant) |
| `tests/Config/StatusLineCommandTest.php:922` | `867ec581f` (block itself `7e2d404310`, 2026-08-22) | W27 / C-TUI-4.c | review-fix inserted the new tick test's block between the pre-existing `--root` prose and `testTheConfiguredWorkingDirectoryIsWhereTheCommandRuns` — the older test lost its doc (costly variant) |
| `tests/Host/AheadOfNeedSkipRulesTest.php:499` | `50800af96` | W24 / C-CTX-5.b | one-line prose naming the session-backend slot sits above `setHeld()`'s `@param` block; `setSessionBackend()` itself carries no block (costly variant) |
| `tests/MCP/SseMcpServerServerRequestTest.php:355` | `d827a5f6` | W22 / C-MCP-3.c | two blocks describe the SAME private `replyTo()` helper; the `@param` attaches, only the English sentence is dropped (cheap variant) |

Path erratum against the brief: the brief names `tests/MCP/McpLoginForkTest.php:309`; the guard's
own output (and the tree) is `tests/Chat/McpLoginForkTest.php:309`. Row added as the guard sees it.

**Cure as adjudicated by the lead:** the expected set becomes an explicit decision log —
`ACCEPTED_STACKED_DOC_PAIRS` — one row per site with wave/step/SHA and the remedy that retires
the row, instead of editing four files that belong to steps welded outside this wave loop
(plan §1.6: a wave-loop fix does not rewrite another lane's files). The guard's headline
docblock is truth-flipped in the same commit ("carries a stacked doc-comment" → "carries an
UNADJUDICATED stacked doc-comment"), and the guard stays live: any NEW pair, or one of these
four moving or being merged, still reddens it.

## Finding 1 — post-cure measurements (all at master `b5cf8dabb`)

| run | before | after |
|---|---|---|
| `--filter RuntimeNoticeSinkDeliveryTest` (exact class) | 25T / 6319A / **1F** | 25T / **6323A** / **0F** (+4A = the four fixture arms, unreachable before) |
| `--filter RuntimeNoticeSink` (bare substring) | 69T / **1F** (per the lead's re-derivation) | 69T / **6521A** / **0F** |
| `--filter 'RecallNotice\|RuntimeNoticeSink\|MemoryRecallBlock'` (weld-note verbatim) | 97T / 6630A / 1F (r2 §2) | 97T / **6634A** / **0F** |
| `--filter 'RecallNoticeRowTest\|RuntimeNoticeSinkTest\|MemoryRecallBlockTest'` (restated exact-class) | — | **62T / 270A / 0F** — reproduces the note's figure exactly |
| adjacent guards `GlobDialectDifferential\|DuplicatedDocBlockLine\|SymbolCitationDrift\|DuplicatedTestHelperDrift` | — | 40T / 19018A / 0F (GlobDialect corpus figure unchanged → added literals are GlobDialect-clean) |

**Discrimination proof (not just green-ness):** deleting the single
`tests/Host/AheadOfNeedSkipRulesTest.php:499` row from `ACCEPTED_STACKED_DOC_PAIRS` reddens the arm
naming exactly that site (diff at `RuntimeNoticeSinkDeliveryTest.php:1429`) — the roster is
load-bearing in both directions, so a row cannot rot silently. Restored and verified by
`md5sum -c` against the pre-mutation copy (`/tmp/opencode/rns.bak`,
md5 `a03d375f7de74280d3f75c557ebdbe56`). `php -l` clean.

**Finding 1 commit:** `b5cf8dabb768aa5e5c0130334d35f0568d7d6f2f` (1 file, 54+/8-;
`%ae` = `%ce` = `detain@interserver.net` on separate greps, 0 `Co-authored` matches).

## Finding 2 — outcome: GREEN-ALREADY at land time, cured by a concurrent master writer

Reproduced red on the start tip (`c013d930d`): `--filter PaletteSupersetDispatchTest` →
7T / 249A / **1F**, `testThePaletteIsTheSupersetAndTheSurvivorsAreExactlyTheJudgedSet` at
`tests/Chat/PaletteSupersetDispatchTest.php:215` diffing in `3 => 'output-style'` (derived 23 vs
roster 22). The row's provenance is `f7d312948` (`C-CTX-4.e: /output-style registry row, handler,
host-command stub, lang keys, regenerated command docs` — W23, welded in batch-18q), i.e. it landed
AFTER W26's own roster snapshot, exactly as the builder's measurement said. Path note: the spec is
`candy-code/builtin-commands/1405-output-style.php` (package-root spec dir, NOT
`src/Commands/builtin-commands/`), and that spec already records its own palette judgement.

My edit to the roster never applied: the `edit` call failed on an exact-match miss because the file
had changed under me — master had moved to `1b5192f42` (`W29 wave-review: finding 2
PaletteSupersetDispatchTest output-style survivor row`, 1 file +10 including an attribution comment
naming W29 finding 2), with evidence record `9ca006685`. So **finding 2 is attributed to that
sibling commit; m2 staged zero lines of it.** Re-verified at tip: 7T / **272A** / **0F**.
No commit of mine contains a `PaletteSupersetDispatchTest.php` hunk (`git show --name-only b5cf8dabb`
= the one guard file only).

## Finding 3 — rider (evidence-only)

Appended as §7 to `lanes/W26/WAVE-REVIEW-2.md` (history not rewritten; 36→75 lines, 6803→9693 bytes):
the C-CTX-6.e weld-note filter restated to the exact-class form with the live 62T/270A reproduction,
and r2's MINOR-2 prune claim for `cc/C-TOOL-6.a` recorded as **OVERRIDDEN — FOREIGN-KEPT, never
prune**. Nothing was pruned by this pass: no branch, no worktree, no `git worktree remove`.

## Commits landed by m2

1. `b5cf8dabb` — `W26 wave-review: stacked-doc arm was red on master - roster the four pairs ...`
   (candy-code/tests/Diagnostics/RuntimeNoticeSinkDeliveryTest.php)
2. this commit — `W26 wave-review: WAVE-REVIEW-2 rider ...` (lanes/W26/WAVE-REVIEW-2.md +
   lanes/W26/FIX-m2.md, evidence only).
