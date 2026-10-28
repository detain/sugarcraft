# C-TOOL-5.b — default formatter table `formatAuto` — REPORT (fix round 1)

Status: COMPLETE (single cohesive commit on cc/C-TOOL-5.b, never pushed).
Builder died pre-commit (phantom-closeout: code was honest on disk, commit 4364a977d never existed); this fixer landed the commit + REVIEW-1 items 2/4.

## Deliverable (disk truth, at fix commit)
14 files, +628/−33. At REVIEW-1 measurement the 12 modified files were +324/−32;
the fix round added +3/−1 (DocFigure window comment + line) and the NIT cite.
Split: 12 modified +327/−33; 2 NEW files +301 lines —
`src/Format/FormatAutoTable.php` (166) and `tests/Format/FormatAutoTableTest.php` (135).
- FormatAutoTableTest: **6T** (79A) — NOT 13T as the builder's dead report claimed.
- FormatRunnerTest: **+6 methods** (114 lines); PostEditFormatHookTest: **+2 methods** (47 lines).
- There is NO `FormatRunnerHookTest` file (builder claim false).
- Durations roster for weld: **ONLY** `tests/Format/FormatAutoTableTest.php`.

## Gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS)
- `--filter 'FormatAutoTable|FormatRunner'` → OK 38T/208A.
- `--filter 'PostEditFormat'` → OK 15T/70A (combined 53T/278A green).
- `--filter 'DocFigureProseDrift'` → OK 91T/3970A (after widen).
- `--filter 'LayeredSettings'` → OK 109T/427A.

## REVIEW-1 dispositions
1. CRITICAL commit — landed as the single lane commit, detain author, zero trailers.
2. MAJOR DocFigureProseDriftTest:2290 hooks() excerpt window 9000→12000 IN THIS COMMIT;
   mutation-discriminated: reverting to 9000 reddens exactly the gate-needle leg, restore md5-clean.
3. MAJOR (recorded, no change): `src/Config/LayeredSettings.php:620` `userTierOnlyKeys()`
   +1 `'formatAuto'` is design-forced (user-tier bool) but OUTSIDE the declared file set —
   named in the commit body for weld safety.
4. NIT FormatAutoTable.php:12 cite fixed to `\SugarCraft\Code\LSP\LspLauncher` (verified class exists).
5. MINOR — this block is the rewrite; builder's 51-file/+5406 figures were false.

## Touch rules
No changes to suite-figure.json, parallel-tests-durations.tsv, or candy-code-web/dist.
