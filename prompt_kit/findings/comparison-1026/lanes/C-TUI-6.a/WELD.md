# C-TUI-6.a — WELD (welder, 2026-10-31)

STEP=C-TUI-6.a ROLE=welder. Sole master writer. W38 LATE member; sibling of welded C-TUI-6.b/6.c Windows adapters.

## Loop artifacts — re-statted by THIS welder from disk, read IN FULL

- REPORT.md 11,139 B @18:49:38 (builder, 2 picks, porcelain 0)
- REVIEW-1.md 9,785 B @19:11:28 — VERDICT: FINDINGS — 1 MAJOR / 1 MINOR / 2 NIT. WELD-ELIGIBLE: NO until MAJOR-1 cured.
  MAJOR-1 = r1 catch of a REAL base-green regression the builder's nine-stem gate missed: Bash.php:535 rewritten
  SINGLE-quoted 'bash -c ' where PermissionGateScopedWriteTest:479 source-scan pins the DOUBLE-quoted "bash -c "
  needle — deterministic 1F (filter 'Bash' 532T/2337A/1F; isolated method 1F). The review loop earning its keep.
  MINOR-1 = PosixShellQuote byte-identity docblock overstated corpus (high-bit chr(128)..chr(255) divergence class).
- FIX-1.md 5,771 B @19:20:41 — pick 3 8f8a7dde0 (3 files +33/−2): one-line double-quote restore (red-before
  PermissionGateScopedWriteTest:: 78T/85A/1F :479 → green-after 0F; 519-payload concat-fuzz byte-equiv; filter 'Bash'
  532/2337 OK) + docblock truth + corpus leg testHighBitNonUtf8BytesRideVerbatim (mutation strip-branch → 1F :140,
  restore md5 b943d1e7). NIT-1 correction recorded §3-only, REPORT byte-untouched.
- REVIEW-2.md 8,334 B @19:31:16 — VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 MINOR / 1 NIT (r1-record ledger wording —
  GlobDialect pin-vs-derived labels inverted in r1 par4; attribution unaffected; closeout wording, never this lane).
  WELD-ELIGIBLE: YES. r2's own runs: PermissionGateScopedWriteTest:: 78T/85A 0F; five-file 64T/141A; filter Bash
  532/2337 OK; TWGR BARE 17/3949; Drift BARE 14/204.

## Landing

Lane /home/sites/cc-cmp-C-TUI-6.a base b45117223 == master at weld (zero external movement all shift).
THREE explicit picks read from the lane `git log --format='%h %s' b45117223..HEAD` (NEVER a range; no -x per t646):
- 6db0d00cb → 3751c1fc4  src layer (8 files +776/−13)
- 1efe40828 → 5204adf95  pins (4 files +982)
- 8f8a7dde0 → 0fc37619a  r1 fix (3 files +33/−2)
Zero conflicts. cat-file -t = commit ×3 in the citing shell; merge-base --is-ancestor vs master OK ×3.

MECHANISM DISCLOSURE: cherry-pick initially refused at unpack because the leads 12-path STAGED dirt (index≠HEAD:
.opencode/agents/cc-cmp-lead.md + 11 lanes/ WELD.md-era files + staged A SERVER-MANUAL-SECTION.md) blocks the
sequencer clean-index requirement. The staged set is provably DISJOINT from every pick (zero candy-code entries).
Resolution = content-preserving `git reset` (mixed, to HEAD): every staged entry carried worktree==index (`M `/`A `
flags), so disk contents were NEVER touched; the 12-path list was recorded first
(git diff --cached --name-only → md5 e5f6b9456d2046a8c2231d0eb6e014f0); landing used TRUE cherry-pick; at terminal
the exact staged state is RESTORED by re-staging those 12 paths (disclosed in final report). reflog =
[reset: moving to HEAD (no-op content)] + 3 cherry-picks, zero foreign movement.

## Merged-tree proofs (cwd /home/sites/sugarcraft, range b45117223..0fc37619a)

- Zero-lang: `git diff --name-only` across the span grep-count 0 for lang/en.php AND sanctioned mirrors
  docs/protocol/web-strings.en.json + candy-code-web/src-web/lib/strings.en.generated.ts ⇒ CliLangCatalogueTest::/
  LangParityTest:: UNOWED (say so per brief).
- TreeWideGuardRosterTest: absent from every per-pick name-list (grep 0 ×3) — ZERO new rows; BARE run at merged tip
  17T/3949A GREEN ⇒ all master rows survive BY TEXT at CURRENT positions (test re-derives live).
- Bash.php:535 PRESERVED: merged worktree reads `"bash -c " . PosixShellQuote::quote($script);` (double-quote form,
  PermissionGateScopedWriteTest:479 green at merged tip — the r1/r2 WARN honored).
- 6.h collision-union: NOT materialized — master tip at weld == base (6.h not yet welded; slot law held);
  first-landed (this weld) owns the union of BackgroundShellRegistry/Bash/ProcessContainment regions.
- DIG/ReadPathCensus: guard files absent from all three picks (name-diff); merged-tree co-run
  DescriptorInheritanceGuard|ReadPathCensus = 34T/5957A GREEN unedited — no-row claim re-derived.
- Trio+dist ABSENT: candy-code.v1.schema.json + docs/SERVER.md + src-web/protocol/generated.ts + candy-code-web/dist
  grep-count 0 across merged span ⇒ UNOWED; no src/Protocol/ touched ⇒ gen-protocol-schema UNOWED; vitest UNOWED.

## Merged-tip gates (cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS plain pipe; NON-ZERO every Tests: line;
ambient "PHPUnit Deprecations: 2" in every run, house, not lane)

- Own five-file combined: 64T/141A OK  · solos: PosixShellQuoteTest 30/34 · WindowsShellTest 13/26 ·
  WindowsTaskKillTest 13/50 · BashWindowsSpecTest 5/14 · BackgroundShellWindowsArmTest 3/17 (sums exact vs 64/141 —
  THESE live counts mint §7 durations facts)
- PermissionGateScopedWriteTest:: 78T/85A OK (0F) — MAJOR-1 closed on merged tree
- --filter Bash era: 532T/2337A OK, no 1F
- PAIRING BashSandboxTest|BubblewrapHardeningTest ONE process: 39T/192A GREEN (db1441a61 shape holds)
- LayeredSettingsTest:: anchored: 99T/413A OK (0F; 00fedf519 cure holds, nothing folded)
- BARE TreeWideGuardRosterTest 17T/3949A OK · BARE DuplicatedTestHelperDriftTest 14T/204A OK
- RuntimeTest:: 153T/595A OK · BuiltInToolCorpus 53T/191A OK · (BackgroundShellInteractive|BackgroundShellWallFallback|ToolSecurity) 97T/305A OK
- Generators: gen-settings-doc/gen-tool-docs/gen-command-docs --check ALL rc0 — regen leg NOT taken (zero measured
  drift; docs rode the picks)
- Root tools: tools/check-one-type-per-file.php rc0 (62 libs 3367 files 0 problems) ·
  tools/check-child-lifetimes.php rc0 (61 libs, 33 proc_open sites, 9 findings, 9 accounted, 0 problems) — VERIFIED, not UNVERIFIED
- Foreign reds ride ACCEPTED-FOREIGN roster report-only cure-NONE (StderrEmitterCensus PtyRelay 6.j 75174ea40 ·
  BootstrapLaunchFormat doc-leg · GlobDialect 1F assert-NEITHER-number · ReadmeSuiteFigureDrift staleness pair with
  THIS welds +64T-class carry DISCLOSED — suite-figure.json/durations.tsv untouched, battery owns · LaunchContextDelegates
  4.g df106a84c · keepWarm/KeyHelp/HelpTest-service-tier/BinCandyCodeDispatch:1893/AttachPaging/OneSided:435/
  SettingsEditor:189/ChildStderrCapture/AccessorDeprecation era shapes). Excluded-not-run per brief:
  PtyRunToolTest::testExpectMiss leg, RegistryTest shrink item, ForkedChildReaper Sessions/BackgroundShellRegistryTest leg.

## Security posture (design §6.3 ruling-a, r1+r2 judged, not re-litigated)

EXEC-as-data quoting corpus vs LIVE escapeshellarg oracle (byte-identity; NUL ValueError parity; 519-payload
zero-behavior-change concat-fuzz on the :535 cure); honest UNCONFIRMED-BY-RUN labels for real-Windows
taskkill/PATHEXT/NUL semantics (Linux box; static-cert + injected fakes per the welded 6.b/6.c posture);
tree-kill pid-refusal CLOSED pre-runner + single-proc_open + spawn<drain<proc_close structural cert; zero new cdef
(FFI intptr_t law untriggered), sugar-pty zero-touch (name-diff); all four kill arms route to the ONE
WindowsTaskKill::tree() — no second reaper; ProcessTree Linux-only; CapturesProcessOutput string|array widen
DISCLOSED enabler, consumers green (cdGuard/capture + killTree-consumer eras in r1/r2); cmd.exe NEVER consulted
(structural absence pins); BASH_MISSING_NOTICE names Git for Windows.

## §7 HAND-OFFS (LISTED, NEVER FIXED here)

1. durations.tsv rows at THIS welders LIVE merged-tip counts: PosixShellQuoteTest.php 30T/34A ·
   WindowsShellTest.php 13T/26A · WindowsTaskKillTest.php 13T/50A · BashWindowsSpecTest.php 5T/14A ·
   BackgroundShellWindowsArmTest.php 3T/17A.
2. Suite-figure pair +64T-class carry DISCLOSED (pinned 25,537/609,443 vs live — battery re-pin owns; NEVER edited here).
3. RESTRICTED_TOOL_NAMES += 'Script' — LEAD ruling still pending.
4. web.yml gen:strings — LEAD rider.
5. r2 NIT ledger-wording disposition: r1 line-30 GlobDialect pin-vs-derived labels inverted ({310,245 ↔ 311,360}
   orientation) — closeout ledger wording, attribution unaffected, never this lane.
6. /tmp/opencode/cc-tui6a scratch (fixer 519-fuzz harness) — owner glance at program sweep.

## WARN NEXT WELDER (6.g)

6.g OWNS the protocol-trio regen: schema +2/−1 MEDIA_REF html + generated.ts +1/−1 :227 + SERVER.md TWO manual
canvas rows verbatim regen-wont-derive + dist rebuild + ProtocolSchemaDrift by-design green-at-regen.
6.g base predates db1441a61 ⇒ its BashSandbox|Bubblewrap co-run MUST read GREEN on THIS merged tip (this weld:
39T/192A) — if red there, the drift is 6.g-s to report, not retroactive to this weld. Master moves — re-derive
EVERY union (en.php/TWGR census prose) BY TEXT from the CURRENT blob.

## Absorption proofs (appended AFTER commit-1, at terminal — final uncommitted increment by design per t979/t1020)

(PENDING below)
