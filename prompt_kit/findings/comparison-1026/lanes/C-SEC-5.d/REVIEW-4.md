# C-SEC-5.d REVIEW-2 (round 2, real commit)

Repo /home/sites/sugarcraft · worktree /home/sites/cc-cmp-C-SEC-5.d · branch cc/C-SEC-5.d
HEAD b64922b50 on base e7e22823a · ONE commit · porcelain 0.
NOTE: the dispatched hash 6c73e5f8e does NOT exist (git cat-file fails) — the landed
commit is b64922b50; r1's content PASS describes the same tree (blob-verified below).

## Verdict
VERDICT: CLEAN (0 CRITICAL / 0 MAJOR / 2 MINOR / 2 NIT — all non-blocking, no amend needed)

## Scope (duty 2)
git diff e7e22823a..b64922b50 = exactly the 8 declared files
(App.php, KeyboardHandler.php, LaunchContext.php, Renderer.php, en.php, PERMISSIONS.md,
LaunchTrustDialogTest.php [new], KeyboardHandlerTest.php).
The feared stray prompt_kit/findings/comparison-1026/lanes/W10/WAVE-REVIEW-1.md is ABSENT
from the commit (it does exist on master; no add/add conflict awaits the welder). The
phantom 6c73e5f8e evidently carried it; b64922b50 dropped it. No REPORT.md in the commit
(evidence-dir duty, see MINOR-1).

## Cure blob-verification (duty 1)
- withTrustDialog/grant leg: PRESENT — App.php ctor field, mutate carry, handleKey modal
  branch (before settings view), resolveTrustDialog closure calling the landed
  Bootstrap::grantProjectTrust (:8096) with $this->settingsWriter (one door, no parallel path).
- chain-walk test: PRESENT — testGrantingARowWalksAppThroughTheOneDoorAndAnswersAsANotice
  (real Cmd invoke, notice message, and on-disk trustedProjectHooks==[realpath root]).
- 'could not be read' token: ABSENT from the test and the codebase (MINOR-2); the failure
  pin instead uses a throwing SettingsWriter door ('ld door refused') propagating through
  grantProjectTrust's real Throwable catch — substantively equivalent, mutation-solid
  (a fabricated notice fails the disk assert; an un-invoked callback fails the failure-string assert).

## Semantics spot-check (duty 3)
Esc/n: close, zero writes, no notice (pinned). Bare/empty census: null seed (trusted-exclusion
lives in pendingProjectTrust's trusted-predicate, pinned by C-SEC-5.a). Once-per-root latch:
process-memory static in LaunchContext, asked-set before return, test-seam reset. -p/serve:
structural roster-walk pins withTrustDialog( to App+LaunchContext only, launchTrustDialog( seed
exactly once, and NonInteractive.php names none of the four tokens. Renderer.php: conditional
[renderer] lawful per design brief (full-band composite lives beside renderMediaForm).

## Hygiene (duty 4)
%ae and %ce each grep to detain@interserver.net separately; empty body, zero trailers;
single commit; porcelain 0 (tree never touched — harness was /tmp redirects only).

## Gates reproduced (duty 5, cwd candy-code, plain pipe env -u LINES -u COLUMNS, PtyRunToolTest never in scope)
- 'LaunchTrustDialog|KeyboardHandler': 93T/12754A OK — EXACT r1 match (g1-tip.log).
- 'TrustKeyDocumentationDrift|LangParity|MouseModalGuard': 41T/3638A OK — EXACT (g2-tip.log).
- 'App|LaunchContext': 1319T/109326A/1S OK — EXACT (g3-tip.log).
- 'Renderer|Menu|KeyboardHandler': 1544T/178453A, sole failure
  KeyHelpTest::testSlashHelpNoLongerOpensTheKeyboardReference — EXACT r1 shape (g4-tip.log).
- Base re-proof: main-tree master (KeyHelpTest + fixtures byte-identical e7e22823a..master)
  reproduces the same /skills-hint red in isolation → pre-existing, lane-clean (g5-base-keyhelp.log).
- Added: 'ReadPathCensus|StderrEmitterCensus' 114T/21379A OK (new decodedFile read site in
  LaunchContext rides the LayeredSettings choke; no census flip owed) (g6-tip.log).

## Non-vacuity (duty 6)
Red-before: base blobs of App.php/LaunchContext.php contain ZERO trustDialog references
(grep -c == 0, both), so the test file's setUp/withTrustDialog calls raise undefined-method
Errors on all 6+1 methods = the claimed 7E, structurally proven. Callback-liveness: success
test asserts the real on-disk grant; failure test asserts the writer's own error string round-
tripping through Bootstrap::grantProjectTrust — neither passes if the closure were fabricated
or never invoked.

## Findings
- MINOR-1 (step-local): no REPORT.md anywhere (not the commit, not the evidence dir, not
  /tmp/opencode) though the cure ledger cites it for the Renderer justification;
  justification independently re-derived here. Closeout/welder should record it.
- MINOR-2 (out-of-scope ledger): cure wording "asserts live '**Refused:** project settings
  could not be read' (Bootstrap.php:7098)" matches nothing on disk; grantProjectTrust lives
  at :8096. Amend the wave ledger row, not the code.
- NIT: App.php `use ...Tui\TrustDialog;` inserted between Settings imports (import order non-alphabetical).
- NIT: renderTrustDialog() has no render pin (mirrors renderMediaForm precedent; zone law covered by MouseModalGuard).

## Weld reminders (closeout law, not lane faults)
scripts/parallel-tests-durations.tsv needs a LaunchTrustDialogTest.php row (unlisted files run
zero shards) and the suite-figure/README pair carries +7T at the battery.
