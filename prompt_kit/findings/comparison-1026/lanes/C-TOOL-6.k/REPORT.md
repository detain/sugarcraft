# C-TOOL-6.k — ShellSend tool, Bash interactive background runs, /shells send (lane report, live)

STATE: coding started. Lane /home/sites/cc-cmp-C-TOOL-6.k branch cc/C-TOOL-6.k base 77beb227d.

## Grounding reads (done, by text)
- design-tool.md:1310 block VERBATIM (lane copy). 6.j WELD.md + C-TUI-6.a WELD.md BY TEXT.
- Substrate: BackgroundShellRegistry send()/status()/read()/decodeCursor/startInteractive bodies;
  PtyRun::decode+KEY_TOKENS; ShellOutput full (head/paint/parseCursor); ShellStop full (model);
  PermissionGate decide :734-795 + evaluateAuto keystroke arm + isRmRfRootOrHome :1497 + ShellSubjectTools
  (stepTexts/judgedShellTexts/NAMES); Runtime::WRITE_CAPABLE_TOOL_NAMES :798; ShellsCommand full;
  Bash :583-704 refusal+background branch+backgroundAnswer+schema/description regions; lang cmd.shells.*/host.shells.*
  regions; PERMISSIONS.md :155-175 breaker+classes block, :746-760 cross-binding; web-strings scope = web.* only
  (host.*/cmd.* OUTSIDE mirrors — zero web-touch).

## Decisions (from the design text)
- Position 47 (design's 43 taken by SkillWorkshop; C-TOOL-6.d precedent: ordering metadata; honest comment + deviation report).
- ShellSend implements Tool+AcceptsShellOwner+BuildsFromCatalog+TakesToolCallId+CarriesSessionState
  (design lists 3; ShellStop/ShellOutput carry the 5-member set — registry rows must ride the fork boundary;
  deviation documented in class docblock + this report).
- send() is fully bounded (r+ non-blocking open, bounded write) — no tool-side hang; relay-not-ready retry is
  a small bounded window (6.j hand-off NIT: sub-ms .pid→fifo window) — EXEC-confirmed via live cat leg.
- Refusals: status() door shapes the two named refusals (non-interactive / settled) with the design's exact
  sentences; send() stays the authoritative write door; unknown/foreign = the engine's identical 'tracked by
  this launch' no-trace shape.
- ShellOutput: drop `readonly` (class stays final) for a per-launch humanInputs memo — only mechanism that
  answers "N times since your last read" without editing the welded engine file (out of my files.modify).
  Fallback wording when no memo entry exists yet (post-rejoin): honest total, not a delta. No readonly pin in
  ShellOutputTest/ParallelSafeDocblockTest (verified by grep).
- Bash: refusal block removed; route inside the EXISTING background branch AFTER sandbox wrap (design: same
  sandbox-prefixed $cmd). Plain background + registry-less Bash still refuses fail-closed at the registry door.

## Gates so far
(none run yet — coding)

## FINAL STATE (lane complete)
Picks on cc/C-TOOL-6.k (each cat-file -t commit in the citing shell):
- 07cb3d9df C-TOOL-6.k: ShellSend tool, Bash interactive background runs, /shells send (src wiring + docs regen + word maps + flips)
- 32bc2b8ef slice 2: relay-readiness fix + 3 new test files + /shells send legs + honest-surface removals
- cdb7ba30e slice 3: ShellOutputTest interactive leg
- dbb176b16 TreeWideGuardRoster WALKS_A_DIRECTORY_THE_TEST_MADE rows (BashInteractiveBackgroundTest + ShellSendTest)
Author/committer detain 1/1 per commit, Co-Authored-By 0, no -x, explicit-path commits; porcelain 0.

master: moved 77beb227d→0fe9fa6a2 (6 weld picks); base is a strict ancestor (merge-base proof) — lane FF-rebaseable by the welder; NOT rebased lane-side (picks law).

Send-path safety trace: Bash route passes sandbox-wrapped $cmd to engine startInteractive
(src/Tools/BuiltIn/Bash.php background branch, route-order scan-pinned); keystrokes ride ONLY
the welded send() fifos (src/Background/BackgroundShellRegistry.php:617 — engine blob diff-empty);
ZERO new spawn sites (check-child-lifetimes 61 libs/33 sites/9 accounted, 0 problems).
E646: wait_ms clamps [0,10000] (this call's patience); relay-ready window 2000ms declared ceiling;
no kill clock on the shell anywhere in the send path.

Gates (all env -u LINES -u COLUMNS plain pipe cwd=candy-code):
- typing family 74T/299A green; adjacent 14-stem batch 683T/1721A green; doc-guard batch
  294T/9960A green (ToolDoc/CommandDocs/PermissionsIntro/ReadmeRoster/SettingsTier/DocFigure/
  Corpus/LangParity/CliLangCatalogue/SlashDispatch/Subcommands); TreeWideGuardRosterTest BARE
  17T/3965A green; DuplicatedTestHelperDriftTest BARE 14T/204A green; PtyRunToolTest minus the
  SIGHUP method 12T/93A green (lookahead filter); check-one-type-per-file 0 problems.
- §7 NEW *Test.php live counts (each run clean): ShellSendTest 18T/78A ·
  BashInteractiveBackgroundTest 6T/36A · ShellSendPermissionTest 10T/39A. NO durations rows
  minted (closeout duty). suite-figure.json untouched — staleness pair remains foreign-era.

EXEC-vs-UNCONFIRMED: relayReady EXEC-discriminated (neutering lost bytes on 2 live legs; M1 2F);
attach-window predicate M2 1F; gate decide-arm M3 2F; breaker-arm M4 2F; Bash-route M5 2F on
route legs (scan legs survive = structural defense, honest); memo M6 1F. UNCONFIRMED-BY-RUN:
ShellsCommand send_not_ready refusal text (needs a never-attaching relay; the shared
ShellSend::relayReady predicate is pinned live tool-side); fifo-open/write engine failure
shapes inside the window (engine's own domain, 6.j-pinned).

Deviations (all reported, none wider than design): position 47 vs design 43 (disk 43-46 taken;
C-TOOL-6.d benign-precedent; gloss verbatim; honest comments in test + commit); five-interface
set like ShellStop/ShellOutput siblings (CarriesSessionState needed for fork-boundary rows);
ShellOutput shed `readonly` for the display-only humanInputs memo (only mechanism that answers
"since your last read" without editing the welded engine file; docblock + test state it);
relay-readiness wait added to BOTH user paths per 6.j hand-off #3; unreachable decoded-empty
refusals removed (fabrication hygiene); dead host.shells.send_usage key removed.

Collisions: STOP-and-report NEVER triggered — all design regions editable; engine blobs
diff-empty (proof: git diff name-only grep PtyRelay|BackgroundShellRegistry|
BackgroundShellInteractiveTest|PosixShellQuote|WindowsShell|WindowsTaskKill = EMPTY); zero
candy-code-web paths; protocol trio/SERVER.md/dist/suite-figure/durations untouched.

Adjudicated foreign reds (cure NONE): environment "PHPUnit Deprecations: 2" pair on every run
(house ambient); NEW roster finding — MenuBarLabelDerivationTest red BASE-INHERITED at
77beb227d (doctor/recap derived rows absent from its hand table; base-blob grep zero hits; my
diff touches no command-registry input). ReadmeSuiteFigureDrift staleness pair untouched per law.
