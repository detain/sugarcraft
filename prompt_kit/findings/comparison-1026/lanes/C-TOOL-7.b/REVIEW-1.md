# C-TOOL-7.b — REVIEW-1 (round 1, authoritative first review)

Lane: cc/C-TOOL-7.b @ a486eda44 ("Ls keeps a bounded heap instead of collecting every entry"), worktree /home/sites/cc-cmp-C-TOOL-7.b.

VERDICT: CLEAN — 0 critical, 0 major, 2 NIT (informational).

## 1. Plan fidelity — PASS
- Bounded heap instead of collect-every-then-usort: `Ls.php:319-381` — `SplMaxHeap` (anon subclass, `compare()` = strcmp on basename) of the `maxEntries` smallest + `$total` counter; plan §4 (:1435-1443) satisfied verbatim.
- Cap test: heap never exceeds maxEntries (`:367-372`); total carried to the note (`:255-263`).
- Declared file set exact: `git show --stat a486eda44` = only `src/Tools/BuiltIn/Ls.php` + `tests/Tools/BuiltIn/LsTest.php` (+73/+59), matches brief files.modify.
- :306 truth-flip honest: new docblock (:300-316) says the walk is NOT the bounded cost (collect-then-sort was) and that filtering, is_dir, and jail realpath STAY full-walk because ignored/escaped counts must be exact; realpath-stays-full-walk restated at the call site (:349-351). This matches the brief's risks note and design's defer-realpath-where-possible resolving to "not possible".

## 2. Correctness — PASS
- SplMaxHeap semantics: compare() positive ⇒ lexicographically larger = higher priority ⇒ root = largest retained basename. Smaller arrival: extract()+insert (`:369-371`); larger: dropped (`elseif` false). Root-before-evict invariant correct; count bounded.
- Order == old usort prefix EXACTLY: extraction drains largest-first, `array_reverse` (`:374-380`) yields strcmp-ascending. Basename uniqueness holds: `StreamingDirectoryLister::list()` is a single opendir/readdir walk of ONE directory (`src/StreamingDirectoryLister.php:38-58`); each entry yielded exactly once; `.`/`..` filtered — equality claim stands (see NIT-1 for the racing-walk caveat).
- Tuple gained `total`: single caller `execute()` destructured at `:243`; grep proves no other `collect(` callers in src/ or tests/.
- Truncation arithmetic: `; %d more entries not shown` with `total - count(entries)` and stopped-after with `count(entries)` (`:255-263`) — when the window is full, count(entries)==maxEntries, identical to old wording's value; prefix `... [truncated: stopped after %d entries` byte-preserved.
- `testCapStopsAtMaxEntriesAndAnnouncesItself` (LsTest.php:230-242) unchanged in the diff; its `'[truncated: stopped after 2 entries'` + `'This list is PARTIAL'` pins remain real substrings of the new note and still bound behavior; the NEW exact arithmetic leg lives in the huge test (`stopped after 50 entries; 19950 more entries not shown`).

## 3. Fork-only drain — N/A; prior MAJOR is a phantom
- `captureShellOutput` occurs ZERO times in candy-code src/tests (grep: 0 hits), and this commit touches NO shell-capture code — `git show a486eda44 --stat` is the proof. There is no drain-on-close/early-close mechanism in this lane; the only "drain" in Ls.php is the comment "Extract drains largest-first" (:375).
- Therefore the historical claim "captureShellOutput is shared by the foreground Bash path… the squashed commit embeds the fork-only cure" is not applicable to C-TOOL-7.b: nothing to cure, nothing cured — foreground Bash semantics byte-identical by absence (diff scope). That MAJOR was cross-labeled from another lane; recorded, no code action.

## 4. Tests non-vacuous — PASS
- File run: 19 methods / 41 assertions (builder's disclosure accepted: repo-wide `--filter 'Ls'` substrings-match 6639 tests; the file-level 19T is the meaningful figure).
- Memory leg judged: reproduced the builder's measure.php independently on this box — heap-capped peak-held 23,712 B (REPORT figure EXACT); full-retention proxy 7,634,544 B. Bound 1,500,000 B sits in the 24KB↔multi-MB gap ⇒ reverting to collect-all reddens the memory leg ONLY (answer legs stay green — output identical). Non-vacuous, discriminating both directions.
- Order leg: 20k fixture names via bijection i*7919 mod 20000, str_pad'd — creation order fully scrambled against strcmp order; expected first-50 derived from arithmetic alone (no fs walk, no tool call feeds the oracle). Cap leg, ignored/escaped exactness legs (existing methods) all green.

## 5. Gates (cwd worktree candy-code, plain pipe, env -u LINES -u COLUMNS) — as expected
- `vendor/bin/phpunit -c phpunit.xml tests/Tools/BuiltIn/LsTest.php` → OK (19 tests, 41 assertions), 2.0s.
- `--filter 'BashMissingRoot|BashTimeout'` (PtyRun EXCLUDED; no BashBackground/ShellOutput files exist — the two Bash-named suites are the full non-PtyRun set) → Tests: 23, Assertions: 55, passed; 2 PHPUnit deprecations from files untouched by this lane (base condition).
- five-guard (`ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudgetTest|DuplicatedTestHelperDriftTest`) → 133T/15,823A, Failures: 2 = the staleness pair ONLY (25,537 pinned vs 26,288 live — foreign-era drift + this lane's +1 rides it; weld re-pin duty). Exactly the shape the brief prescribes.
- REPORT's disclosed pre-existing TreeWideGuardRosterTest x2 base-red (unrostered walker MemoryRecallBlockTest) accepted as base-condition; not lane-touched, not in the five-guard window.

## 6. Law — PASS
- porcelain empty; author/committer verified separately: `%ae` and `%ce` both detain@interserver.net; zero Co-Authored-By trailers; base 2ad862baf (W7 tip) — squashed single commit; no suite-figure.json / durations.tsv / dist touches (diff scope); DUR: modify-only confirmed via git show — no new *Test.php, no durations row owed.

## NITs (informational, no fix demanded)
- NIT-1: basename-uniqueness claim holds for a static directory; a concurrent rename/add during the readdir walk can legally yield a duplicate name, under which old-vs-new prefix equality is technically unpinned. Out of the tool's contract (listings race anyway); docblock wording is fine as-is.
- NIT-2: degenerate `maxEntries <= 0` (test seam only, unwired): note reads "stopped after 0 entries; N more entries not shown" where old said "stopped after 0 entries." — cosmetic, no caller can observe it.
