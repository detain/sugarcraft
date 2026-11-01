# C-TUI-6.h step review — round 2 (FINAL branch tip)

Reviewer: cc-cmp-reviewer, read-only. Tree: /home/sites/cc-cmp-C-TUI-6.h. Date 2026-11-01.

## Disk facts (git authority; lead dispatch record superseded)
- Branch cc/C-TUI-6.h, tip bf349531521fa6d63622c208aaf2fa56c6856e3e (cat-file-verified). master..HEAD = THREE commits, not the brief's two: 2f53689bf ship-11-locales, 11d0d647f pin-bin-spawns-en-floor, bf3495315 carry of C-WEB-5.f's four web.chrome.about.logs* keys. Brief SHAs 7e38caae6/258929b1d and base 16d1a94e8 do not exist on this branch — the lane was RE-CUT on base 26462c3ae ("C-WEB-5.f: mark welded"), which is the merge-base and already carries the logs keys in en.php. Lead paraphrase stale; content claims re-checked against disk (all hold).
- Master (worktree ref b40c2c78e, includes brief's 0bab5f9ef) vs base: `git log --oneline 26462c3ae..master -- candy-code/` EMPTY — zero src/lang/test drift between lane base and master tip, so the weld cannot desync parity. 0bab5f9ef IS an ancestor of master.
- Diff scope = exactly the design's file-set: 11 created lang/{fr,de,es,pt,pt-br,zh-cn,zh-tw,ja,ru,it,ko}.php, README.md +16, docs/CONTRIBUTING.md +19, NEW tests/LangCommunityLocaleTest.php (+150), tests/Integration/BinCandyCodeDispatchTest.php (+72/-2). en.php, LangParityTest.php, src/ byte-untouched (diff master...HEAD empty for all three). No weld-only files (suite-figure.json, durations.tsv, dist/) in the diff. No stray files.

## Gates (self-run, cwd=candy-code, env -u LINES -u COLUMNS, plain pipe)
- `--filter 'Lang'`: **OK 635 tests, 52058 assertions, Skipped: 1, PHPUnit Deprecations: 2** (29.99s). Covers LangParityTest (exact sorted key-set + placeholder parity per locale — green means full parity incl. the 4 logs keys), LangLaunchLocaleTest, LangCommunityLocaleTest. The skip cannot originate from the new file (zero skip logic in its 150 lines) — inherited environmental canary; the 2 deprecations even appeared in the 1-test standalone run, so they are bootstrap-level, pre-existing, not lane-attributable.
- `--filter 'BinCandyCodeDispatchTest'`: **138 tests, 876 assertions, Failures: 1** — sole red is testEveryVerbTheParserAcceptsIsOfferedByAllThreeCompletions (:1958, '--worktree' missing from Subcommands::OPTIONS). BASE-PROVEN FOREIGN: the leg reads only src (ArgvParser.php:861 branches '--worktree'; grep count of `'--worktree' =>` in src/Cli/Subcommands.php = 0; test leg and both files byte-untouched by the lane; src/ untouched vs master). This is the §7-banked "BinCandyCodeDispatchTest --worktree cure" rider — recorded, not charged.
- New pin standalone: `--filter 'testTheChildSpeaksEnglishEvenWhenTheRunnerSitsOnAForeignLocale'`: **OK 1 test, 6 assertions**. Discriminating by construction: hostile putenv LC_ALL+LANG=de_DE.UTF-8 (both spellings, LC_ALL outranks), positive legs assert status 0 + 'Usage:', negative leg asserts the German counterpart line (derived positionally from de.php['cli.help.screen'], never hardcoded) is ABSENT — without the floor the child answers German and both legs fail. Consistent with builder's claimed floor-neutered mutation red.
- `--filter 'ReadmeRosterDriftTest|DocFigureProseDriftTest|EnvRosterDriftTest'`: **OK 140 tests, 47834 assertions** (48.85s) — no doc figure falsified by the README/CONTRIBUTING additions.
- `php -l`: all 11 community lang files + en.php + both changed/new test files — 14/14 No syntax errors.

## Key census (awk/uniq + phpunit authority; python3 and bare `php <file>` are denied to this reviewer by the agent permission map — LangParityTest's sorted array_keys compare is the stronger authority)
- Strict top-level `    'key' =>` line count: **4,036 in ALL twelve files** (en + 11). uniq -d empty (0 dups); uniq count == line count. The 4/8-indent-only census proves en/de key-line multisets are md5-IDENTICAL (468869f3059d73a2b3c3c9205742281b) — genuine full structural parity; all 4036 keys sit at 4/8 indent in both files (4036 == 3263+773).
- Lead's "3,675" is a stale r1-era figure (pre W42-tree en.php); disk truth at this base is 4,036 — parity itself holds (every file equal, guard green). Record-only erratum.
- The 4 logs keys (real names web.chrome.about.logs / _empty / _no_log / _refresh, en.php:4324-4327) verified present and genuinely translated in every sampled locale (fr "Journaux", ja "ログ" etc.).
- Fallback logs exist: /tmp/opencode/cc-C-TUI-6.h/fallbacks/{11 files}.txt, 21-25 lines each (256 total, ~0.58% per locale at the 4,036 denominator; the lead's "~0.3%" imprecise — existence claim holds, content not pinned by any guard).
- Structural-census claim in the brief ("was 3,671 pre-5.f, expect 3,675" arm): NO such arm exists on disk and NO hard-coded key numeral exists anywhere in the test, README note, or CONTRIBUTING text (grep 3675/3671 zero hits). The shipped guard derives everything from the tree (scandir completeness; positional German-line lookup via require). Brief description stale; no hard-coded census — requirement satisfied by absence and by derivation.

## Design-block DoD (design-tui.md L1161 + plan §6 ruling "h") — all met
- 11 ruled codes, bare base codes + pt-br regional; machine-translation posture per ruling.
- Every key incl. web.* translated, {placeholder}s byte-preserved (placeholder-parity leg green). Model-read text stays English — stated in README + CONTRIBUTING.
- README Languages note near Install lists ALL 11 codes with the `LANG=fr_FR.UTF-8 candy-code` example, worded per the design.
- docs/CONTRIBUTING.md carries the translation and strict-parity rule (no stale "machine translation plus native review" wording).
- LangParityTest untouched under the strict option ✓; strict parity is now genuinely enforced at the tree.
- Guard test DoD forms all present: inLocale sample non-English; WebStrings answers the locale via the REAL API (negotiate()/for() — the design's "forLocale" method does not exist in src/Protocol/WebStrings.php; guard exercises the surface that ships); regional-tag fallback (`<code>-xx` → base; zh bases correctly land on en with `en` in the provider alphabet so no vacuous pass) — plus set-completeness and POSIX-spelling arms beyond the DoD.

## Bin harness fix (2nd/3rd commits) — verified minimal
- 4 hunks, +72/-2: EN_LAUNCH_FLOOR const with honest docblock (LC_ALL outranks LANG; minimalEnv can't be poisoned — whitelist ships no locale var); new pin; runBin docblock truth-flip; inherit path `$env === null` now array_merge(getenv(), FLOOR), minimalEnv path byte-identical. proc_open site is the pre-existing harness one (no new spawn site, no roster touch). The 13 reddened English-wording pins are the file's own argv-only cases; root cause (locale-hostile inherit) removed by the floor, and the new arm would redden if the floor were deleted.

## Laws
- Commits: `%ae` grep-c detain = **3**; separate `%ce` grep-c detain = **3**; full `%B` Co-Authored-By = **0**.
- No E646 surface; no generated-file churn; no weld-only files; no `**Refused:**`-shaped or glob-shaped string literals in the new tests (scandir+str_ends_with house shape).
- strict_types first, final classes, one type per file, PSR-12 in both PHP files; comments say WHY.
- Main-tree base proof skipped: /home/sites/sugarcraft carries 1,032 lines of foreign WIP; substituted with src-level proof + §7 rider match (documented above).

## Findings
- NIT-1 (out-of-scope, lead record): dispatch brief stale vs disk (SHA set, commit count, base, 3,675/0.3% figures, phantom "structural census" arm). All content claims verified true against disk; welder should re-derive SHAs from the branch, not the brief.
- NIT-2 (step-local, prose, unpoliced): CONTRIBUTING.md "~360 of the 3,675 entries" numerals lag the final tree (fallback rows measure 360-380/file against 4,036 keys; de.txt=378 lines). Hedged with "roughly", no guard depends on it; one-line touch-up optional at weld.
- Recorded foreign, not charged: --worktree OPTIONS standing red (§7 rider); Skipped:1 + Deprecations:2 bootstrap-level.

## Conclusion
All 11 locales are real, complete, placeholder-safe and structurally identical to the final en.php; strict parity holds at the base and master cannot drift it before the weld (zero candy-code commits between); every gate green except the base-proven foreign leg; the bin fix is minimal and its pin discriminating; all plan §1/§6 laws honored.

VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 step-local MINOR / 2 NIT (both record-only)
