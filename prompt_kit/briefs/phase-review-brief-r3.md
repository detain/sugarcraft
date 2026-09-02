# PHASE 3 CLOSE REVIEW — cycle 3 (FINAL) — REVIEWER BRIEF

## Your role
You are the Phase 3 close reviewer, cycle 3 of at most 3 — the FINAL cycle under the cap. You
are READ-ONLY with respect to the repository: no edits, no commits, no git config changes
anywhere. The only mutations you may make to the reviewed tree are temporary experiments, and
every file you touch in the sandbox worktree MUST be restored before you finish
(`git -C <worktree> checkout -- .` and verify `git -C <worktree> status --porcelain` prints
nothing at the end). A clean verdict from you closes Phase 3. Findings go to a dedicated fix
agent, never to you; if the loop cannot close, the orchestrator escalates the full state to the
user — you do not grind.

## The sandbox
Worktree `/home/sites/prompt-step-P3.CLOSE-r3`, branch `prompt/P3.CLOSE-r3`, HEAD `cf41aacd6`
(= current master tip, which contains the P3.audit-fix-3 merge `99227d29c`; bookkeeping-only
commits may move the tip above the merge sha — `git diff 99227d29c..cf41aacd6 -- sugar-crush/`
is EMPTY, so the reviewed `sugar-crush/` content is identical at both). `git status --porcelain` must
be EMPTY when you start — verify it first. vendor/ is a hardlinked copy (`cp -al`); PSR-4
verified to print the WORKTREE paths:
`SugarCraft\Crush\ => /home/sites/prompt-step-P3.CLOSE-r3/sugar-crush/src` and
`SugarCraft\Crush\Tests\ => .../tests`. NEVER run `composer install` here — it de-symlinks/
replaces vendor and breaks the sandbox.
## Your scratchpad
`/home/sites/prompt-scratch/P3.CLOSE-r3/review-1/` — yours alone.
- ORCHESTRATION-RULE-3: never write into the scratch ROOT or another agent's subdirectory;
  generic file names at a shared root are forbidden (shared-name backups + restores across
  agents = silent cross-contamination). `rm -rf` only inside your own subdirectory.
- ORCHESTRATION-RULE-2: never `git init`, never commit, never touch git config anywhere except
  inside your own scratchpad subdirectory. A reviewer once clobbered the repository's committer
  identity and left a stray commit on master.

## Required reading (all on disk, current)
- `/home/sites/prompt-scratch/P3.CLOSE-r3/phase3-step-texts.md` — the six Phase-3 step texts
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

## 3. The change-set

Review the WHOLE phase as one change-set: `git -C <worktree> diff 924c71a0d..HEAD -- sugar-crush/` — re-derived fresh at the sandbox base: **30 files, +22,911 / -603**. If your re-derivation differs, the tree moved underneath you: report that BEFORE reviewing anything.

The Phase-3 merges on master's first-parent line (the full window also contains separately-reviewed commits — see below; they are ordinary change-set for you):

| sha | step | what it did |
| --- | ---- | ----------- |
| 379ecc7d6 | P3.S1 | system-prompt assembly: 7 layers, `<env>` last in both assemblers |
| dabcd27f7 | P3.S2 | diff-after-write sections populated from real git state |
| 74cabae7f | P3.S3 | prompt snapshot + honest git-state caveat |
| f2af06eaa | P3.S4 | prefix-win ordering in tool-result truncation |
| 6aff0bad1 | P3.audit-fix-1 | close-review cycle-1 code findings |
| 1279d91cf | P3.S4-fix-1 | S4 review fixes |
| 405252a41 | P3.S5 | write-signal marked from the engine loop (Runtime path) |
| 5cabca4a8 | P3.S5-fix-1 | alias-channel fail-open closed in scanner |
| f958ba8e6 | P3.S6 | workflow-path write signal MEASURED, escalated (not wired) |
| 980670c0b | P3.audit-fix-2 | close-review findings A1-A7 |
| 99227d29c | P3.audit-fix-3 | 17 commits 80233c172..60c037932 instrument hardening for the write-primitive scanner and the derived roster (further call shapes now read or declared-residual with pins); a flake fix in the flooding-stderr test; a known-positive control added to one absence guard; ONE project-root resolution now shared by both prompt assemblers. Five internal review cycles — from cycle 4 a dedicated fix agent applied findings while the lead verified with own measurements; the final cycle returned no new findings |

Also inside the diff window but merged for other reasons and each already carried its own review loop: 03d8fed37 (Vertex hoist arm), e0d00b6db (Gemini arm), 33df838d0 + f95546b10 (fixture hermeticity), 72686c380 (CI fix). Everything else between 924c71a0d and HEAD is bookkeeping (prompt_kit/, prompt_*.md) — outside sugar-crush/, outside your scope.

Subsumption note: P3.audit-fix-2 formally owed a 13th review cycle; it was subsumed by close-review cycle 2 (recorded orchestrator judgement). P3.audit-fix-3 ran five cycles of its own. This cycle 3 is the last full-window gate: treat ALL of the above as ordinary change-set.
## Phase-loop additions (§1.7 — quote it to yourself from disk before you finalise)
Beyond §1.4's 19 checks, a close review must: hunt the seams BETWEEN steps — interactions across
the six steps that no single step's review could see — and re-run the whole suite in this tree
yourself. Do not trust any figure in this brief; every one of them is yours to falsify.

## Suite rules
- Run from the sandbox CHECKOUT ROOT (`cd /home/sites/prompt-step-P3.CLOSE-r3`), serial,
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

## 5. Expected suite figure — falsify it

The step lead measured at the synced tip `5f716b34d` (cwd = checkout root, serial, `</dev/null`, box-quiet probe 0): **10,556 tests / 163,809 assertions / 1 skipped / 0 failures**. The merge into master imported ZERO sugar-crush files, so if `git -C <worktree> diff --stat 5f716b34d..HEAD -- sugar-crush/` prints nothing, that figure provably describes your HEAD — re-run anyway (your run is the gate's run), state your prediction in `<scratchpad>/review-1/prediction.md` BEFORE executing.

Delta vs the never-edited P0.S1 baseline (10,351 / 160,648 / 1): **+205 tests / +3,161 assertions**. If your headline differs by a few assertions, reconcile PER-CLASS with `python3 /home/sites/sugarcraft/prompt_kit/tools/cmp.py` against your own earlier JUnit before believing or reporting anything — known flake family: MouseModalGuardTest moves +/-3 with COLUMNS/LINES alone (viewport artifact; never report it as a headline figure).

## 6. Census attack — the DERIVED roster, and its fresh closures

Phase 3's headline defence is the DERIVED roster: `tests/TreeWideGuardRosterTest.php` walks every test file and derives which guards scan src/ or tests/ wholesale. Claimed at HEAD: **roster 67 / candidates 83 / walkerFiles 181 / testFiles 440 / unaccounted 0**, and the roster test itself: 17 tests / 1,101 assertions. Audit-fix-3 added classifier arms but claims the five derivation numbers UNCHANGED — the new arms are MEASURED-LATENT (zero live population: `class_alias` count in src/ is 0; planted shapes live inside string literals the classifier lexes as T_ENCAPSED). Verify all six numbers; then ATTACK THE DERIVATION itself — helper-built file lists, differently-spelled scans, symlinked dirs, non-.php files, dynamic globs, anything that makes a walking test invisible to the roster.

The nine-file asserted subset (pre-check, cwd = sugar-crush/, serial, `</dev/null`): expect **OK (176 tests, 31,255 assertions)** at HEAD `cf41aacd6` — MEASURED 2026-09-02 over exactly the nine `HAND_MAINTAINED_CENSUS_SET` members named at `tests/TreeWideGuardRosterTest.php`. The same nine measured OK (176, 31,245) at `470e43569`; audit-fix-3's new guard arms are +10 assertions with no test added. IT SAID **320 tests / 29,926 assertions** here, and that figure does not reproduce over these nine at any sha in the window — it came from a pickup verification note recorded with no file list, and the 320-count set was never the nine (a 29,926 total cannot contain a 31,255 population). Corrected by direct measurement; the lesson is rule 1 — a census figure travels with the file list it was measured over, or it is not a measurement.

Channels audit-fix-3 newly CLOSED — attack each specifically (a miss here means the scanner/roster silently under-counts writes or guards again):
- `use ... as` imports resolved for walker classes AND the scanner's alphabet
- `class_alias()` literals in EVERY string spelling: single-quoted incl. escaped-backslash decode, double-quoted (substitutions ignored — pinned), nowdoc AND indented nowdoc, heredoc AND indented heredoc terminators
- anon classes: `self`/`static`/`parent` inside an anon resolve to its extends primitive
- same-file traits pair with named AND anon users; qualified `use \TraitName;` takes the last segment
- blind-spot table: its HEADER claims must match its ROWS (a cycle-4 finding fixed an over-claim; over-claim it again and that IS a finding). The GlobIterator row is the precedent for honest declared silence — silence declared with a pin is fine; silence NOT in the table is a finding.

## 7. Claims to attack (each was MEASURED by the lead — falsify rather than trust)

1. **Goldens unmoved since 405252a41.** `golden-system-prompt.txt` md5 `32ea749d84938811ac9331419cae7380`, `golden-agent-prompt.txt` md5 `ef0326dd38535aaa2f1d715919bff26e`. Check: `git log --oneline 405252a41..HEAD -- sugar-crush/tests/fixtures/prompt/` must print NOTHING. (Full history: system golden moved 3x across the phase — S1 relocation and S3 caption were the steps' purpose; the third was fixture hermeticity from 33df838d0, NOT a P3.S5 change; agent golden moved 2x, both by design.)
2. **Assembly.** 7 layers; SIX of the seven providers transmit it on complete() and completeStream() — EchoProvider is exempt by a pinned named assertion (known-open, do-not-report). `assembled == golden == wire` survives at **5,176 B** (not the older 5,099), `messages[0].role === 'system'`, `<env>` LAST in BOTH assemblers.
3. **Vertex three arms** (legacy instances / hoisted Google context / real Gemini systemInstruction) routed by model FAMILY; Gemini carries no tools key even when tools are offered (pinned).
4. **Write-primitive scanner fails CLOSED.** Defeat ledger: eighteen-plus defeats across five reviewers — do NOT trust this brief's number, count the ledger from RuntimeTest's own defeat pins. The construction channel is now READ (anon classes, same-file named subclasses, aliased parents, keywords in every scope class). The rule "an unknown spelling costs a FALSE POSITIVE, never a silent miss" must hold EXCEPT for the declared residuals (each pinned): cross-file trait users, cross-file/imported parents, NAMESPACED extends parents, same-file CONSTANT and computed class_alias names, roster-side self-in-subclass. TRY TO BE THE NEXT DEFEAT: invent a write or walk shape, drive it through the shipped scanner AND the shipped roster classifier with a same-script known-positive control.
5. **Roster derivation** six numbers exact (section 6).
6. **Write signal:** wired on the Runtime path (engine loop marks each step; Agent path MEASURED-NOT-WIRED, escalated — §18 row reads ESCALATED, NOT WAIVED). WorkflowEngine has 5 per-step render call sites (:1252 and :1294 both inside executeVerificationStage = double render per verify stage). AgentResult::__construct has 8 params, none tool-named. Project-root resolution is unified (a seam from an earlier window, now closed): Runtime and Agent assemblers share ONE project-root resolution — a regression here IS a finding; it is no longer known-open.
7. **Determinism:** clock/platform/cwd injectable; both goldens pin byte-for-byte, the agent golden pinning `Agent::systemPrompt()`.
8. **audit-fix-2 src purity:** across 980670c0b, `src/Runtime.php` and `src/Agents/Agent.php` changed NO executable token — re-derive as ELEMENTWISE token-stream identity after stripping T_COMMENT/T_DOC_COMMENT/T_WHITESPACE (Runtime.php 4,366 tokens, Agent.php 1,270, both sides). Do not use md5 of a re-serialized stream — the serialization domain is unstable (a cycle-2 formulation defect).
9. **audit-fix-3 scope purity:** re-derive `git diff --name-only 3634aa1cb..60c037932` and check every path against the declared file list in `/home/sites/sugarcraft/prompt_kit/briefs/P3.audit-fix-3-step-brief.md` (9 files; `src/Workflows/WorkflowEngine.php` was declared conditionally). Anything outside = finding (check 10).
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

Additions to the known-open list above (all recorded — reporting them is NOT a finding):
- EchoProvider transmission exemption and the 5,176 B assembled figure are folded into claims 2 above.
- Commit identity history: 8 commits in the audit-fix-2 lane carry `detain@gmail.com` (a recorded close-review finding). Un-rewritable history; recorded; enforcement is now a standing pre-merge check (all 17 audit-fix-3 commits verified `detain@interserver.net`).
- F-4R-3: the named-class `parentOf` hop is value-redundant with the roots fixpoint and structurally unobservable; it is PINNED by a deletion experiment (-145 assertions). Removal was judged an orchestrator call, left pending — report neither the hop as a defect nor its removal as a fix.
- MouseModalGuardTest +/-3 assertion swings under COLUMNS/LINES are the known viewport artifact — reconcile per-class, never by headline.
- RuntimeNoticeSinkDeliveryTest carries a whole-tree stacked-doc-comment gate; it reddens on stranded `@param` blocks anywhere in tests/. It is a gate, not a defect.
- The absence guard testNoAdditionalWorkingDirectoriesLineIsEmitted now carries a known-positive control through the same scanner (the RR4-F2 follow-up, closed in audit-fix-3) — hunting other guards that pass on a dead renderer remains fair game.
## Hard prohibitions
Never push; never global `pkill`; never `--no-verify` or `core.hooksPath` tricks; never
`composer install`/`update` (it de-symlinks/replaces vendor); never modify any git config; never
weaken, skip, rename-out, or delete an existing test (§1.11 — escalating a finding that requires it
is allowed, deciding it is not); never propose REMOVAL of dormant, unwired, or unreachable code as
a fix (§1.10 — the only outcomes are wire-it, build-it-out, or ask-the-user); use `/usr/bin/grep`
for tree-wide scans (a bare `grep` here is ugrep and honours .gitignore); never edit any file in
either repository except temporary experiments inside the sandbox worktree that you fully restore.

## Findings discipline
APPEND each finding to `/home/sites/prompt-scratch/P3.CLOSE-r3/review-1/findings-cycle-3.md` the
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
