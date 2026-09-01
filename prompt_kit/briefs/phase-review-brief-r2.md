# PHASE 3 CLOSE REVIEW — cycle 2 — REVIEWER BRIEF

## Your role
You are the Phase 3 close reviewer, cycle 2 of at most 3. You are READ-ONLY with respect to the
repository: no edits, no commits, no git config changes anywhere. The only mutations you may make
to the reviewed tree are temporary experiments, and every file you touch in the sandbox worktree
MUST be restored before you finish (`git -C <worktree> checkout -- .` and, for files the scanner
tests could leave, verify `git -C <worktree> status --porcelain` prints nothing at the end).

## The sandbox
Worktree `/home/sites/prompt-step-P3.CLOSE-r2`, branch `prompt/P3.CLOSE-r2`, HEAD `470e43569`
(= current master tip). `git status --porcelain` must be EMPTY when you start — verify it first.
vendor/ is a hardlinked copy (`cp -al`); PSR-4 verified to print the WORKTREE paths:
`SugarCraft\Crush\ => /home/sites/prompt-step-P3.CLOSE-r2/sugar-crush/src` and
`SugarCraft\Crush\Tests\ => .../tests`. NEVER run `composer install` here — it de-symlinks/
replaces vendor and breaks the sandbox.

## Your scratchpad
`/home/sites/prompt-scratch/P3.CLOSE-r2/review-1/` — yours alone.
- ORCHESTRATION-RULE-3: never write into the scratch ROOT or another agent's subdirectory;
  generic file names at a shared root are forbidden (shared-name backups + restores across
  agents = silent cross-contamination). `rm -rf` only inside your own subdirectory.
- ORCHESTRATION-RULE-2: never `git init`, never commit, never touch git config anywhere except
  inside your own scratchpad subdirectory. A reviewer once clobbered the repository's committer
  identity and left a stray commit on master.

## Required reading (all on disk, current)
- `/home/sites/prompt-scratch/P3.CLOSE-r2/phase3-step-texts.md` — the six Phase-3 step texts
  VERBATIM from prompt_plan.md lines 1409-1700 (each step's Goal / Files / Done-when). This is
  your authority on what each step had to do.
- `/home/sites/sugarcraft/prompt_plan.md` §1.4 (lines 366-501) — the 19-check review bar,
  INCLUDING the second half of check 19 (added 2026-08-31; the first half alone let a defect
  through).
- §1.7 (582-608) the phase loop — its close-review instructions bind you.
- §1.10 (827-873) removal is not an available outcome.
- §1.11 (874-896) what counts as a test (deletion experiments mandatory; annotations and
  existence-checks are NOT tests).
- §16 (2776+), especially §16.8 (3062-3256): rule 2 ship-the-generator, 15 derive-the-roster,
  40 corrections-travel, 42 three-part-form, 44 brief-authority.
- §17 (3274+) invariants; §18 (3461+) deliberately-not-built.

## The change-set
`git -C /home/sites/sugarcraft diff 924c71a0d..470e43569 -- sugar-crush/` =
**27 files changed, 19,768 insertions(+), 598 deletions(-)** — re-derived 2026-09-01. If your
number differs, the tree moved: report that BEFORE reviewing anything.

## The merged steps in the window (first-parent, in order)
| sha | what |
|---|---|
| `379ecc7d6` | P3.S1 — `<env>` to the end of the system prompt |
| `dabcd27f7` | P3.S2 — emit the working diff only on the step after a write |
| `74cabae7f` | P3.S3 — snapshot semantics and the honest caveat |
| `f2af06eaa` | P3.S4 — measure the prefix win |
| `6aff0bad1` | P3.audit-fix-1 |
| `1279d91cf` | P3.S4-fix-1 |
| `405252a41` | P3.S5 — wire the write-signal into the engine loop |
| `5cabca4a8` | P3.S5-fix-1 — close the alias channel that failed OPEN |
| `f958ba8e6` | P3.S6 — second-assembler gap disposition (measured escalation + pins) |
| `980670c0b` | P3.audit-fix-2 — six files: src/Runtime.php, src/Agents/Agent.php, tests/RuntimeTest.php, tests/Agents/AgentTest.php, tests/Context/EnvironmentBlockTest.php, tests/TreeWideGuardRosterTest.php (4,870+/16-) |
Also in the window, reviewed separately but IN SCOPE for your findings: `03d8fed37`
(P1.audit-fix-1), `e0d00b6db` (P1.audit-fix-3), `33df838d0` + `f95546b10` (P2.audit-fix-1),
`72686c380` (CI-fix-1). Everything else in the window is bookkeeping (prompt_kit/, prompt_*.md).

SUBSUMPTION, stated plainly: P3.audit-fix-2 was merged after 12 review cycles without its owed
cycle 13 (an orchestrator judgement — reversible). Your cycle SUBSUMES that cycle 13: audit-fix-2's
six files are ordinary change-set for you, and anything you find there is an ordinary finding.

## Phase-loop additions (§1.7 — quote it to yourself from disk before you finalise)
Beyond §1.4's 19 checks, a close review must: hunt the seams BETWEEN steps — interactions across
the six steps that no single step's review could see — and re-run the whole suite in this tree
yourself. Do not trust any figure in this brief; every one of them is yours to falsify.

## Suite rules
- Run from the sandbox CHECKOUT ROOT (`cd /home/sites/prompt-step-P3.CLOSE-r2`), serial,
  `</dev/null`; every figure you report names its cwd, serial, and `</dev/null`.
- Box-quiet probe before any full run: `ps -eo cmd | /usr/bin/grep -c '^php .*phpunit'` must
  print 0. Do NOT use the old bracketed `'[v]endor/bin/phpunit'` probe — run in the same command
  as the suite it returns a FALSE 1 (harness `bash -c` argv self-match).
- State predictions in `<scratchpad>/review-1/prediction.md` BEFORE any suite run; record misses
  honestly.
- Reconcile any moved total with a per-class JUnit diff FIRST:
  `/home/sites/sugarcraft/prompt_kit/tools/cmp.py`.
- Under a live tty exactly two tests fail (Chat\CompactModelSummaryTest, MouseModalGuardTest) —
  a viewport artifact. Your non-tty runs must not reproduce it; if they do, that IS a finding.

## Expected full-suite figure (a claim to falsify)
**10,547 tests / 163,710 assertions / 1 skipped** — measured by the orchestrator at `980670c0b`.
`470e43569` differs from `980670c0b` only under prompt_kit/ and the three prompt_*.md files
(verify: `git -C /home/sites/sugarcraft diff --stat 980670c0b 470e43569` shows nothing under
sugar-crush/) — so the figure is claimed to describe your HEAD. Delta vs the never-edited P0.S1
baseline `10351 / 160648 / 1`: **+196 tests, +3,062 assertions**.

## Census — now DERIVED, and that is the thing to attack
The guard roster is no longer a hand-maintained nine-file list. `tests/TreeWideGuardRosterTest.php`
walks every test file under sugar-crush/tests/ and asserts each test that scans src/ or tests/
wholesale is on the roster. Claimed figures at `980670c0b` (the roster test itself: 16 tests,
1,082 assertions, green in this sandbox): **roster 67 guards, candidates 83, walker files 181,
test files 440, unaccounted 0**. Your census duty is to attack THE DERIVATION: what kind of
tree-wide guard could escape the walker's recognition — a guard that builds its file list through
a helper, a differently-spelled scan, a symlinked directory, a non-.php file, a dynamic glob?
The old nine-file census set survives as an ASSERTED subset; as a pre-check expect
`OK (176 tests, 31245 assertions)` (cwd: the sandbox's sugar-crush/).

## Claims to attack (MEASURE each; a failed claim IS a finding)
1. **Goldens.** `tests/fixtures/prompt/golden-system-prompt.txt` md5
   `32ea749d84938811ac9331419cae7380`; `golden-agent-prompt.txt` md5
   `ef0326dd38535aaa2f1d715919bff26e`. UNMOVED SINCE `405252a41` (P3.S5's merge). Across the whole
   of Phase 3 the system golden moved 3 times and the agent golden 2 times, each claimed legit:
   P3.S1 and P3.S3 goldens-moving-was-the-point; the third system move was fixture hermeticity
   (`OS version: <host>`) introduced by `33df838d0` (P2.audit-fix-1), NOT P3.S5. Verify with
   `git log --oneline -- sugar-crush/tests/fixtures/prompt/` plus a look at each move's diff.
2. **Assembly.** `Runtime::buildSystemPrompt()` emits 7 layers into `CompleteRequest::$systemPrompt`;
   all seven providers transmit it; on the golden fixture context assembled == golden == wire bytes
   (measured 5,099 B), `messages[0].role=system`; `<env>` LAST in BOTH assemblers (Runtime and Agent).
3. **Vertex.** Three arms routed by model FAMILY: legacy instances (chat-bison), hoisted Google
   `instances[0].context`, and real Gemini `:generateContent` with `systemInstruction` + streaming.
   Gemini cannot call tools and a test pins that absence.
4. **The write-primitive scanner** fails CLOSED; its name alphabet covers `T_NAME_RELATIVE` (a
   namespaced `\file_put_contents()` call is scanned). Claim: twelve defeats across four reviewers,
   every one found ON A GREEN SUITE (the alias-channel fail-open closed at `5cabca4a8`; the twelfth,
   the T_NAME_RELATIVE miss, closed at `980670c0b`). A name-based scanner is structurally
   incompletable (N1) — an unknown spelling must cost a FALSE POSITIVE, never a silent miss.
   Try to be the thirteenth defeat.
5. **The write signal** is WIRED on the Runtime path and MEASURED-NOT-WIRED on the Agent path. The
   per-step seam is real and live in `Workflows/WorkflowEngine.php` (5 prod call sites; :1105/:875
   render per stage; :1252/:1294 render twice in one verification stage). Measured via a logging git
   shim: 1 render = 5 git subprocesses (3 suppressed), K stages = 5xK, ProcessExecutor dispatch = 10
   renders, every stage sees ONE DISTINCT PROMPT. `AgentResult::__construct` has 8 params and NO
   tool-call field; the worker complete frame carries only output/tokensUsed/costUsd. The §18 row is
   ESCALATED, not waived.
6. **Determinism.** Clock, platform, cwd injectable; both goldens pin byte-for-byte; the agent golden
   pins `Agent::systemPrompt()`.
7. **audit-fix-2 src purity.** Outside its two test-heavy additions, `980670c0b` changed Runtime.php
   and Agent.php only in doc comments: executable-token streams identical both sides (Runtime.php
   4,366 tokens md5 `2b15a37a...`; Agent.php 1,270 tokens md5 `c472f3d5...`). Re-derive; if a token
   moved, the claim is dead — report it.

## Known-open — the orchestrator HOLDS these; do NOT report them as new findings
EnvironmentBlock.php:288 branch read uncapped ('255-byte limit' is per-component) — fold into P5.S3.
BOTH fence-escape vectors pinned-not-fixed, folded into P5.S3 in one planned diff: (i) `</env>`
forged via diff bodies — an unstaged edit to any tracked file can inject the closing fence, LIVE
by construction since P3.S5 merged; (ii) A6 branch-name raw interpolation (the one git read not
through `gitField()`). PermissionGate.php:691 hard-codes `'mcp__'` where Runtime reads the
authority (diverges in the PERMISSIVE direction). ChildStderrCaptureTest.php:199-204 keys
`'Context/'` by prefix with no count. sugar-crush/phpunit.xml doc comment says 'all 6465 tests'
(stale). TWO SURVIVING MUTATIONS declared in the tree: removing the `closeOverDelegates()` call
site changes nothing; dropping only the token-class filter in `namesOneOf()` while comparing exact
token text. F2: `gitSubprocessesDuring()` in AgentTest.php — DuplicatedTestHelperDriftTest
normalises comments away, so doc-block divergence is invisible. Bootstrap.php:1462 pointer comment
deliberately REVERTED (needs a docs/plans-owning lane). N1 per-tool `writesTree():bool` vs
working-tree fingerprint — user decision pending on WHICH. N2 SymbolCitationDriftTest holes (:290
backtick scraper class-part lacks '/'; :335 discards fabricated ...TestClass before resolution;
polices only test-symbol citations). N3 tests/RuntimeTest.php is a third scratch-repo fixture
missing the log.date / format.pretty / .git/info/attributes hardening (its own queued step).
N4 EnvironmentBlock.php:855 inline literal vs sibling NO_PROCESS_REASON constant.
N5 RuntimeTest asserts trait file order from getTraits() (a semantic no-op reorder would red it);
`phpFilesUnder()` follows directory symlinks. P1.audit-fix-2 still-open items (RR2 F1/F3/F4/F7,
RR1 F2/F6); RR3 F5 doubled separator before every skill body (Skill.php:109 + Runtime.php prepend
— FREE file, re-derive lines). Vertex legacy arm: `formatMessages` emits 'role' where instances
spells 'author'; `defaultPredictor()` non-rawPredict branch drops `setParameters()` — PINNED BY
DESIGN (a repair must red it). Publishers mistralai/meta/ai21 unrouted. AuditHook.php:103-105
putenv measurement true-warm/false-cold. P2.S4's deletion experiments UNRECOVERABLE (four
compressed worklog entries) — its guards unproven until re-run. Docs edits + progress.json dormant
machinery (wire or build out, never delete). TWO awaiting-user escalations, non-blocking: Gemini
tools shaper; workflow-path write-signal disposition (wire across WorkflowEngine+AgentResult+IPC
vs leave the pinned cost standing). php-cs-fixer is NOT installed — do not report; but eye-visible
PSR-12 violations DO get reported (check 17). If you find anything WORSE than, causally UPSTREAM
of, or outside the declared scope of any item above — report it; the list prescribes nothing.

## Hard prohibitions
Never push; never global `pkill`; never `--no-verify` or `core.hooksPath` tricks; never
`composer install`/`update` (it de-symlinks/replaces vendor); never modify any git config; never
weaken, skip, rename-out, or delete an existing test (§1.11 — escalating a finding that requires it
is allowed, deciding it is not); never propose REMOVAL of dormant, unwired, or unreachable code as
a fix (§1.10 — the only outcomes are wire-it, build-it-out, or ask-the-user); use `/usr/bin/grep`
for tree-wide scans (a bare `grep` here is ugrep and honours .gitignore); never edit any file in
either repository except temporary experiments inside the sandbox worktree that you fully restore.

## Findings discipline
APPEND each finding to `/home/sites/prompt-scratch/P3.CLOSE-r2/review-1/findings-cycle-2.md` the
moment you make it — not at the end (eight of ten findings were once lost to context before
writing). A finding carries: number, file:line, why it is wrong, WHAT WOULD MAKE IT NOT WRONG,
tree position (sha), and a label — MEASURED / OBSERVED / INFERRED / UNVERIFIED.

## Report format (your final message)
1. Tree position reviewed + `git status --porcelain` empty at end (proof you restored everything).
2. MEASUREMENTS section: full suite figure (cwd/serial/</dev/null stated), prediction-vs-actual,
   roster pre-checks, golden md5s, diffstat — every figure re-derived in YOUR tree.
3. Numbered findings with the six fields above. Findings outside any step's declared scope are
   REPORTED, not prescribed against.
4. An explicit list of what you did NOT check.
5. If NO FINDINGS: an accounting for all 19 §1.4 checks AND the §1.7 close-review additions, each
   with its evidence. 'Looks good' when a problem exists is the only way you can fail.
