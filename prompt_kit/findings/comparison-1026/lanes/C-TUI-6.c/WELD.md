# C-TUI-6.c — WELD record

WELDER=fresh-cut (prior welder session confirmed zero-write: master was 07593d459 at start, cherry showed no absorption)
date=2026-10-31 background=true · ROLE=welder (sole master writer) · STEP=C-TUI-6.c

## Artifacts re-statted by THIS welder from disk (before any write)
- REPORT.md 7,504 B @12:04:33 (builder; three picks; disclosed deviations: _data/sugar-pty.json one-file-beyond-design edit, ConPtySlavePty::spawn cols/rows ?int=null widening mirroring PosixSlavePty, README 'Library layout' staleness report-only, UNCONFIRMED-from-Linux legs: ConPtyLiveTest real-spawn + affected-libs stdout probe).
- REVIEW-1.md 8,912 B @12:12:07 — `VERDICT: FINDINGS — C=0 M=0 MINOR=1 NIT=2 · WELD-ELIGIBLE YES` (MINOR-1 = REPORT's ConPtyMasterPtyTest 15T vs live 14T; NIT-1 factory docblock trailing space; NIT-2 spawn @param $env REPLACES-prose).
- FIX-1.md 4,406 B @12:15:15 — dc4fcdc22 docblock-only 2 files +7/−4 (NIT-1+NIT-2 fixed); carries the dedicated "WELDER: MINT FROM THIS BLOCK, NOT FROM REPORT.md" fact set.
- REVIEW-2.md 5,244 B @12:23:15 — `VERDICT: CLEAN — C=0 M=0 MINOR=0 NIT=0 · WELD-ELIGIBLE YES`; ruling: live ConPtyMasterPtyTest is 14T/27A; the stale 15T in REPORT.md tolerable ONLY because the weld mints §7 at 14T/27A and discloses the supersession.

## 14T/27A SUPERSESSION (explicit, per r2's binding condition)
Builder REPORT.md:23 says ConPtyMasterPtyTest "15T". WRONG. The figure of record is **14T/27A** — r1 executed it, FIX-1's full-suite arithmetic corroborates (a 15th leg forces 821 vs measured 820), and THIS welder re-verified at the merged tip: `--filter 'ConPtyMasterPtyTest::'` → "OK (14 tests, 27 assertions)". Every §7/ledger figure downstream takes 14T/27A; REPORT.md is preserved byte-untouched as the builder's historical artifact.

## Picks
master pre-weld 07593d459; lane /home/sites/cc-cmp-C-TUI-6.c branch cc/C-TUI-6.c base c785f3ef8 (ancestor-verified). Four explicit picks read from the lane (`git log --reverse --format=%H master..cc/C-TUI-6.c`), NEVER a range, no -x, zero conflicts:
- 5aade73ed → **7a05fb635** adapters+tests (10 files)
- d919da7ea → **37f56a5d9** factory/exception/README/CALIBER (6 files +152/−48)
- 7610e1b07 → **63e119d44** WINDOWS_LIBS + ci.yml + affected-libs + docs (6 files +15/−9)
- dc4fcdc22 → **a997c78d4** MINOR docblock (2 files +7/−4)

## Merged-tree verification (all by THIS welder on master tip after picks)
- Name-diff 07593d459..HEAD: exactly sugar-pty/** + .github/workflows/ci.yml + scripts/affected-libs.php + docs/MATCHUPS.md + docs/_data/sugar-pty.{json,body.html} + docs/lib/sugar-pty.html. candy-code paths: **0** (grep -c '^candy-code' = 0) → no protocol trio, no dist, no suite-figure/durations implication; en.php untouched.
- Author law landed blobs: %ae detain ×4, %ce detain ×4, Co-Authored-By ×0.
- ci.yml YAML re-parse VERIFIED-BY-EXEC: php -r yaml_parse_file → jobs=13 (builder's disclosed shape); windows-test 'Run tests' gains the tests\Windows elseif + sugar-pty rationale comment; affected-libs.php diff is the ONE WINDOWS_LIBS append line — every other dynamic-discovery behavior byte-intact.
- sugar-pty gates (cwd=sugar-pty, env -u LINES -u COLUMNS, plain pipe): Windows 109T/843A/3S · InterfacesTest:: 53T/166A · (PtySystemFactoryTest|UnsupportedPlatformExceptionTest):: 33T/80A · ConPtySystemTest:: 16T/60A · **ConPtyMasterPtyTest:: 14T/27A (line of record)** · EnvRosterTest:: 14T/345A · FULL lib suite 820T/4015A/20S EXACT r1 figure (covers ConPtyChildTest 9T/58A + ConPtyLiveTest 1T/1S inside the Windows shape). All NON-ZERO anchored stems.
- Universal merge gates: pairing BashSandboxTest|BubblewrapHardeningTest co-run 39T/190A GREEN (db1441a61 cure holds, never touched); LayeredSettingsTest:: 99T/411A 0F (cure 00fedf519 rides the lane base — not folded). (Both runs carry the era-standard 2 PHPUnit deprecation notices; zero failures.)
- BARE guards: TreeWideGuardRosterTest 17T/3914A + DuplicatedTestHelperDriftTest 14T/204A green — rows-by-TEXT survive; this lane adds zero candy-code files (name-diff above) and zero new guard rows (r2 name-list proof).
- Generators --check rc0, ZERO drift: tools/gen-docs.php, tools/check-docs-assets.php, candy-code gen-command-docs, gen-tool-docs, gen-settings-doc (docs land inside the lane's own picks; no regen leg needed).
- ENGINE-ONLY design note: the Contract adapters are exercised for real on the Windows CI leg (WINDOWS_LIBS now includes sugar-pty; scoping runs tests\Windows only — the rest of the suite is POSIX-pinned and cannot pass on a Windows cell). On Linux the single new markTestSkipped is ConPtyLiveTest, env-gated non-Windows + PHP<8.4/FFI; r2 verified it cannot mask reds (git log -S names only pick 1; the skip pair rode every 109T/3S and 820T/20S shape).

## Absorption + cleanup
- `git cherry master cc/C-TUI-6.c`: 4× `-`, zero `+` — no patch-id false-positive, no content proof needed.
- worktree ../cc-cmp-C-TUI-6.c + branch cc/C-TUI-6.c removed AFTER the proof.
- Surviving lanes: cc/C-TOOL-6.d · cc/C-TOOL-6.g · cc/C-TUI-6.g + pre-existing foreign cc/C-TOOL-6.a (untouched).
- Dead-sessions orphan scratch /home/sites/rev-6g-r2-mut (detached 27e427e85): removal attempted post-commit; result recorded here and in the return (no force).

## §7 HAND-OFFS — LIST, NEVER FIX (this welder touched none)
- sugar-pty test-file counts as GATE note (repo rule keeps durations.tsv candy-code-only; zero sugar-pty rows exist): ConPtySystemTest 16T/60A · ConPtyMasterPtyTest **14T/27A of record** · ConPtyChildTest 9T/58A · ConPtyLiveTest 1T/1S skip-by-design off-Windows (15.0s per-op ceilings).
- suite-figure/README pair untouched — lib-only picks, confirmed (candy-code path count 0); ReadmeSuiteFigureDrift staleness pair (pinned 25,537/609,443 vs live) stays battery-owned.
- PathGlob re-derive at battery (this weld adds no literals).
- COMPARISON.md windows/pty rows candidates (matrix cells the landing now makes stale-eligible; collection duty at gate/closeout).
- sugar-pty/README 'Library layout' section still pre-ConPTY ('Upcoming POSIX implementation', no src/Windows row) — r1/r2 adjudicated report-only → lead ledger.

## Weld commits
1. `weld: C-TUI-6.c — §4 mark + evidence` = plan_comparison.md mark + lanes/C-TUI-6.c/{REPORT,REVIEW-1,REVIEW-2,FIX-1,WELD}.md.
2. `weld: C-TUI-6.c — run-state fold` = lanes/run-state.json VERBATIM (re-read fresh immediately before commit; md5 disk==blob reported).
§3 untouched (W35 does not close on this weld). NO push (lead owns push, wave close only).
