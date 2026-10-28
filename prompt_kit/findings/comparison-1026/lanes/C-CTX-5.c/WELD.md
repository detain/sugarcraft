# C-CTX-5.c — WELD (2026-10-29, welder: cc-cmp subagent)

Base at weld: master e5dec3b71 (weld: C-SES-6.b). Lane cc/C-CTX-5.c (base cf0cfd0be), 1 commit.

## Pick map
- 585c465ef → 8780474729d27ccbc48a06b9a27c70d280a1f0b2 (✅ mark) — single pick, zero conflicts, 13 files (src/Context + HandlesRunControl + SessionHost + ContextSettings + SETTINGS/CONTEXT docs + lang + 5 test files, NEW tests/Context/CacheTtlIdleFoldTest.php).

## Absorption proof
- `git cherry master cc/C-CTX-5.c` → `- 585c465ef…` (absorbed).
- patch-id --stable SAME; `git show` patches BYTE-IDENTICAL (modulo none).
- %ae=%ce=detain@interserver.net, zero Co-Authored-By.

## Merged-tree gates (cwd candy-code, env -u LINES -u COLUMNS, plain pipe, -c phpunit.xml) — ALL EXACT vs REVIEW-1
- `--filter CacheTtlIdleFoldTest`: **14T/59A** (2 deprecations = foreign AccessorDeprecationTest providers, pre-existing).
- `tests/Context`: **OK 1333T/5275A**.
- band `tests/Host tests/Config/Settings tests/Chat/IdleAutoCompactionTest.php`: **OK 668T/11563A**.
- `--filter Idle`: **256T/20371A** — TIP figure per r1 MINOR/NIT ruling (REPORT's ~235T was the BASE number mis-recorded; weld ledger re-stamped to 256, green either way).
- `--filter SettingsSchemaDocDrift`: **19T/183A** (in-suite SETTINGS.md re-derivation = gen-settings-doc --check equivalent; green on merged tree).

## Bookkeeping
- durations.tsv row ADDED (welder-only file): `tests/Context/CacheTtlIdleFoldTest.php 0.071 14` — measured by dedicated junit run (tests=14, time=0.071213), inserted in the 0.071 tie band after SessionLockTest @741; rows 1541→1542, asc-violations 4==4, set-identity +1 verified.
- suite-figure re-pin carry to closeout: **+21T** (r1 carry math: IdleAuto base 17→+3, SessionHostIdleAuto base 13→+4, +14 new file = +21T; REPORT's +83A assertion carry).
- Known standing reds at weld: the 2 ReadmeSuiteFigureDriftTest staleness figures (closeout-owned, disclosed by lane).

## Carries to closeout (§7)
- r1 MINOR (out-of-scope, report-only): REPORT Idle-filter sentence was BASE not tip — cured here by re-stamp (256T/20371A above).
