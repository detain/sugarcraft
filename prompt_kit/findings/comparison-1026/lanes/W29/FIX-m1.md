# W29 wave-loop FIX-m1 — VERDICT: DONE (MAJOR-1 live-red→green; MINOR-1 comment truth-flipped)

Fixer: cc-cmp-fixer, master-direct, base tip `671105299709170dcace211a333ee93cdb365f58` (re-read matched brief; both target files byte-clean at start via `git status --porcelain -- <paths>` = empty).

## MAJOR-1 — UsageRecordsTest bare-sink count 2→3 (LIVE-RED before fix)

Landed-blob proof (before editing):
- `git show master:candy-code/tests/Session/UsageRecordsTest.php` :633 = `['count' => 2, "usageRecordSink(), null, '"], // the two bare sink sites: compact, turn`.
- Live `candy-code/src/Host/SessionHost.php` has THREE matches of `usageRecordSink(), null, '`: :887 'compact', :1130 'warm' (C-CTX-5.d keep-warm, landed after W29's weld), :2667 'turn'. The file lives at src/Host/ (the review's path; the brief's "src/Session/" slip noted — same file).

Red proof (pre-fix, cwd=candy-code, `-c phpunit.xml`, env -u LINES -u COLUMNS, plain pipe):
`--filter UsageRecordsTest` → **Tests: 27, Assertions: 141, Failures: 1** — `testEverySettleArmCarriesItsSourceLabelAtTheCallSite`: "serve settle arms carry their labels: usageRecordSink(), null, ' / Failed asserting that 3 is identical to 2." at :638. Deterministic, matches WAVE-REVIEW-1.md exactly.

Fix: count 2→3, comment → "the three bare sink sites: compact, turn, warm". No new literals (GlobDialect inert — only the numeral + prose changed).

Green proof (post-fix): `--filter UsageRecordsTest` → **Tests: 27, Assertions: 145, OK** (Assertions +4 = the three arms after the failing one now execute; aux=3/compact=1/goal=1 host pins re-verified live in the same run). 2 PHPUnit Deprecations pre-exist at base (identical in the red run).

## MINOR-1 — Chat.php:7968 theme() comment truth-flip (COMMENT-ONLY, +0T)

Blob proof the comment was false: `candy-code/src/Theme.php::byName()` docblock states "There is no failure path, and that is the point" — unknown names warn ONCE via RuntimeNoticeSink and paint the default (C-TUI-4.a). The stale two-line comment claimed byName throws.

Fix at src/Chat.php:7968 (settings-reset `theme` arm): comment rewritten to the truth; the `catch (\InvalidArgumentException)` here and the one at :21992 (handleThemeCommand) STAND untouched — dormant-code law (wired/kept, never deleted).

Gate: `--filter 'ThemeTest|ThemeFileTest'` → **Tests: 19, Assertions: 68, OK** (comment flip inert; no guard complained). `php -l candy-code/src/Chat.php` clean.

## Commit

Single commit, explicit-path staging of the two cures + this evidence file. Author/committer verified via separate %ae/%ce greps (see commit audit below). No push (owner law).
