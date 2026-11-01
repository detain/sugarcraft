# C-TOOL-6.k — REVIEW-1
VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 MINOR / 1 NIT (reviewer r1, fresh, disk-grounded)

LANE (re-derived): /home/sites/cc-cmp-C-TOOL-6.k, branch cc/C-TOOL-6.k, base 77beb227d, tip dbb176b16,
picks 07cb3d9df · 32bc2b8ef · cdb7ba30e · dbb176b16 (24 files, porcelain 0, AE=4/CE=4 separate greps,
Co-Authored 0). Builder REPORT re-statted 6,534 B, read in full. Design block text-located at
design-tool.md:1310 (lane copy).

## Q1 Send-path safety — PASS
- ShellSend.php:84 Write class, position 47, gloss VERBATIM per design; executes ONLY through
  BackgroundShellRegistry status/send/read doors (:205/:234/:247); keystrokes ride engine send() fifo
  (BackgroundShellRegistry.php:617, blob diff-empty — engine-substrate grep of the 24-file list = 0).
- ZERO new spawn sites: added-line scan for proc_open|pcntl_fork|shell_exec|passthru = 0 (5 ProcessContainment
  hits = comment + import + test-side resetInteractiveAvailabilityForTesting; ProcessContainment not in lane files).
- Bash route: sandbox wrap at Bash.php:663 BEFORE background branch :672, interactive hand-off :688-697 passes
  wrapped $cmd to startInteractive (source-slice route pin testTheRouteHandsStartInteractiveTheWrappedVariable;
  combo reaches registry door fail-closed without registry — BashBackgroundTest truth-flip honest).
- "bash -c " survived at Bash.php:538; PermissionGateScopedWriteTest scan leg (:480) re-run GREEN.
- relayReady budget 2000ms declared ceiling; wait_ms clamp [0,10000] default 500; read-cap inherited
  (engine MAX_READ_BYTES 262144; tool passes null=default); E646-clean (patience ceiling, never kill clock).

## Q2 Permission surface — PASS
- decide() :749 forces Ask by NAME (rules run before mode, session-always skipped, explicit Allow answers first);
  evaluateAuto :1120 Ask before classification AND before counter reset; isRmRfRootOrHome :1515 judges EACH
  DECODED LINE through textTripsRmRfFloor — the fold preserves both original walks (ShellWords parse + segment
  fallback) verbatim; bypass floor pinned (testTheFloorFiresBeforeAnyModeOrGrant).
- ShellSubjectTools roster unchanged (grep 0 ShellSend); stepTexts(ShellSend)= [] pinned live.
- Arm placement = boolean extension inside EXISTING arms, no reordering — 6.e/6.h/6.a ordering pins intact
  (ToolCatalogTest|PermissionGateScopedWriteTest|WriteCapable|ShellSubjectToolsCompleteness batch GREEN 136T).
- Runtime.php:802 WRITE_CAPABLE_TOOL_NAMES += 'ShellSend'. /shells send excluded from READ_VERBS (=['list','output'])
  + isReadOnlyVerb('send') false-pinned; Chat.php untouched per design.

## Q3 /shells send + roster — PASS
- ShellsCommand send leg: raw rest-of-line (no tokeniser), PtyRun::decode, origin 'human', shared
  ShellSend::relayReady predicate ONE-try human path (honest docblock), engine door refusals verbatim,
  foreign==unknown no-trace, humanInputs=1 + cursor threaded (live cat legs).
- Position 47 (43-46 TAKEN by SkillWorkshop/Canvas/Advisor/Script — live grep derived) vs design 43:
  ACCEPT-AND-DISCLOSE, C-TOOL-6.d precedent, gloss verbatim, honest comments.
- Doc guards regen-verified by run: README :2629-era "thirty-seven" + DocFigure "forty-three" census word-maps
  all live-derive green.

## Q4 §1.8 cascade — PASS
- en.php: key-column dup scan (uniq -d over sorted key extraction) = 0 dups; python3 + php tool EXEC DENIED by
  this lane's allowlist — honest denials, shell-shape substitute used. Mirrors: web-strings.en.json is web.*-only
  (host./cmd. keys 0 hits) and strings.en.generated.ts has 0 host.shells keys → keys moved are OUT of mirror scope,
  untouched = regen-consistent.
- TRIO+DIST PROVEN EMPTY: git diff 77beb227d..HEAD over schema.json + docs/SERVER.md + web generated.ts + dist
  (+ suite-figure.json + durations) = zero. ZERO new skips in the three new files; send_not_ready UNCONFIRMED label
  honest — shared relayReady predicate pinned tool-side (testADeletedFifoCostsTheAttachWindowAndRefusesHonestly EXEC green).
- TWGR dbb176b16: two rows, tight file+expression, additive-only (master rows survive by text); BARE TreeWideGuardRosterTest
  17T/3965A GREEN, BARE DuplicatedTestHelperDriftTest 14T/204A GREEN.
- New-file live counts (MINE, rule §7): ShellSendTest Tests: 18, Assertions: 78 · BashInteractiveBackgroundTest
  Tests: 6, Assertions: 36 · ShellSendPermissionTest Tests: 10, Assertions: 39. Modified files machine-diffed:
  all edits ride the surface they police (trailing-3 flip = order-truth, refusal-pin→route-pin = behavior-truth,
  word-map +1s, +6 send legs) — zero weakened legs base..tip.

## Q5 Gates (MY quoted actuals, cwd=candy-code -c phpunit.xml env -u LINES -u COLUMNS plain pipe)
- Typing family SUPERSET (ShellSend|ShellOutput|ShellStop|BashBackground|BashInteractive|ShellsCommand|BackgroundShell):
  Tests: 147, Assertions: 751, OK (broader than builder's 74T/299A stem set).
- Doc-guard batch: Tests: 249, Assertions: 9784, OK. ToolCatalog/ScopedWrite/WriteCapable/Completeness/pairing batch:
  Tests: 136, OK. PtyRunToolTest minus expect-miss: Tests: 12, Assertions: 93, OK.
- "PHPUnit Deprecations: 2" ambient on every run (house). RegistryTest::testAStdoutThatShrinksAlone + ForkedChildReaper:
  report-only per brief, NOT re-run. check-child-lifetimes EXEC denied (php tools refused) — replaced by added-line
  spawn-scan = 0 (Q1). NEVER ran full suite; master untouched throughout.

## Q6 Base-inherited reds — ADJUDICATED
- MenuBarLabelDerivationTest: RED, 1F at :111 — added rows ONLY 'doctor'+'recap'; lane en.php diff carries ZERO
  doctor/recap lines (diff grep 0) and base blob 77beb227d ALREADY has "Run the install checks" (git show grep 1);
  test file + command sources not in the 24-file set ⇒ BASE-INHERITED accepted-foreign (ledger row).
- Census batch (StderrEmitter|LaunchContextDelegates|ForeignAgentPreset|GlobDialect|ReadmeSuiteFigure): 218T/24520A,
  exactly 7F = accepted-foreign: StderrEmitter x2 (PtyRelay-debt), LaunchContext "tools" (df106a84c),
  ForeignAgentPreset PermissionMode (base-carried), staleness pair x2 (NEVER touched), GlobDialect 1F
  (docblock 310,245 vs derived 561×557 — lane adds NO '*'-shaped PHP literal; the lone ?-regex is non-census
  pre-existing shape ⇒ corpus moved zero by this lane, NEITHER-number accepted).
- BashSandbox|BubblewrapHardening co-run: GREEN actual. Residue 0 (porcelain). No glob-shaped or **Refused:**-shaped
  added literals; uniqid-style per-pid+random fixture roots (c6k-send-*, ib-*) ✓.

## Q7 Weld-readiness
WELD-ELIGIBLE: YES — residual reds exactly the accepted-foreign set (incl. MenuBar verdict above); no NEW family.
§7 LIST for welder:
- durations.tsv rows at reviewer-live counts: ShellSendTest 18T · BashInteractiveBackgroundTest 6T · ShellSendPermissionTest 10T.
- Suite carry +34T-class new-file (18+6+10) +7T modified (ShellsCommand 6 + ShellOutput 1) — pinned pair battery-owns, NEVER edited here.
- WARN: FOUR explicit picks 07cb3d9df/32bc2b8ef/cdb7ba30e/dbb176b16 off 77beb227d, read-from-lane, NEVER a range,
  onto MOVED master (6.i welder in flight, master past b1fce23aa); unions BY TEXT on en.php spans / word-maps
  (forty-three + thirty-seven x2) / TWGR rows / ToolCatalog trailing-3 / README + PERMISSIONS + COMMANDS docs /
  Bash.php; 6.i lands tools.client.* protocol rows — shared-file conflict ⇒ STOP; 6.g W35-completer holds slot
  priority when its verdict lands.
- trio/dist UNOWED restated PROVEN (diff-empty, above).
NIT (step-local, no action): human-hand sentence reads "1 times since your last read" — design's own "N times"
template, grammatical at N≠1; leave unless owner wants pluralization.
