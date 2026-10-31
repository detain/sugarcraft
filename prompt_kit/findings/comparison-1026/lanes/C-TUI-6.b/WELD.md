# C-TUI-6.b — WELD.md (welder record, 2026-10-31)

## Preflight (disk-derived)
- master at start: `e3780691e` (= pick-4). Branch `master`. Dirty, NOT touched: `M .opencode/agents/cc-cmp-lead.md`, `M .../lanes/C-WEB-5.g/WELD.md`, `M .../lanes/run-state.json`, junk `?? [input removed...]`, lead `??` lane dirs. No partial `weld: C-TUI-6.b` commit existed; heading 3573 UNMARKED; landed-blob `^#### ✅` = 290.
- Four picks re-read from `git log 288b81f11..cc/C-TUI-6.b`, source → landed:
  - `705e099fa` → `9fb7ceab2` sugar-pty ConPTY FFI primitives (12 paths)
  - `dd8b4fec7` → `0634a741f` review fixes (round 1) — STARTUPINFOW fidelity + handle casts
  - `8ee0d173e` → `4bd369b3b` model HANDLE slots as intptr_t, ending the void*-to-int reads
  - `7953a45d1` → `e3780691e` independent Windows layout pins + cmd.exe metacharacter caveat (3 paths)
  - All four ANCESTRAL (`git merge-base --is-ancestor` ×4, same shell).
- Quiescence: live lanes C-EXT-6.c (candy-code src/Hooks,tests/Hooks,HOOKS.md,en.php,DocFigure,ReadPathCensus) and C-WEB-5.b (candy-code SERVER.md,en.php,ParsedArgs,Serve,Qr*,ServeArgs,QrEncoder tests) — NEITHER touches `sugar-pty/**` (`git diff --name-only` ×2 verified pre-pick). Lane fileset = 12 files, all `sugar-pty/**`. Master `288b81f11..c1a333403 -- sugar-pty/` empty → clean fast picks.
- `git cherry master cc/C-TUI-6.b`: `- - - -` (all four absorbed; proof re-run this session, shown above the prune).

## Merged-tree gates (this shell, e3780691e)
- sugar-pty FULL lib suite, cwd=sugar-pty `-c phpunit.xml` plain pipe: **Tests: 777, Assertions: 3834, Skipped: 19, OK (exit 0)** — EXACTLY r2's numbers; r1 base was 771T/3789A/19S (+6 methods conserved).
- Kernel32ConPtyTest standalone: 18T/108A/2S (fixer's green-shape re-confirmed); group filter (CommandLine|EnvBlock|WideString|Kernel32ConPty|UnsupportedPlatformException|EnvRoster) 94T/1059A/2S; PtySystemFactoryTest 16T/27A — all match r2 (9,733 B REVIEW-2.md, read in full).
- FFI shape survives merge unchanged: `intptr_t` ×30 in the cdef incl. the five HANDLE slots; `lpReserved` present :87; hand-transcribed `EXPECTED_SIZES`/`MSDN_LAYOUT` tables present (:78/:104). Segfault law on record: `(int) FFI::cast('intptr_t',$h)->cdata` on a void*-typed struct member → rc=139 (r2 reproduced; deviation ACCEPTED).
- Guards (BARE class names, cwd=candy-code): `TreeWideGuardRosterTest` **17T/3852A OK** · `DuplicatedTestHelperDriftTest` **14T/204A OK** (both e78d5dc14 cures hold; +2A vs the 6.i weld's 3,850A citation = merged-tree census wobble, DISCLOSED not chased).
- Generators `--check`: gen-command-docs / gen-tool-docs / gen-settings-doc / gen-protocol-schema ALL rc0 "up to date" → NO regenerate commit owed.
- Lane touched zero candy-code/** files → no lang/en.php union, no doc-counter edits; counters stay guard-derived on the merged tree.

## Review loop as the files say (read by the welder, not paraphrased from the lead)
- r1 `REVIEW-1.md` (23,485 B): FINDINGS C=0 M=2 MINOR=4 NIT=2 — M1 STARTUPINFOW dropped `lpReserved`/`lpDesktop` (struct 88/96 vs 104/112) + `cb` sized to the inner struct; M2 `(int)` reads of void* FFI handle members.
- `FIX-1.md`: both cured; the SELF-PROVING layout test (meta-defect: expectation derived FROM the cdef, certifying a wrong struct green) replaced by hand-transcribed MSDN tables; discrimination measured (in-place swap 6 reds naming lpReserved; R2/R4 isolations exact).
- r2 `REVIEW-2.md` (9,733 B, re-statted): CLEAN C=0 M=0 MINOR=0 NIT=2; independently reproduced the rc=139 segfault and ACCEPTED the intptr_t deviation; 5 out-of-tree reds; numbers authority = 777/3834 (fixer's ran 4 low); NIT-2 sugar-core "scalar-typed" wording loose → §7/sugar-core report-only probe candidate (function-RETURN cast semantics un-adjudicated).

## Anomalies carried
- t711: this step was once narrated welded with zero record. t729 (this half): the picks-session receipt cited shas (`a3511f47a`/`4a7cb2e4b`/`f35a6e6a8`/`515e57256`) that exist NOWHERE — welder-self-phantom; the picks themselves are genuine and ancestry-proven twice. This record half is derived entirely from re-read disk state.
