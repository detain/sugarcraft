# C-CTX-3.f (standing intents) — REVIEW-2 (round-2 fix verification, 2026-10-28)

Lane tip **bac64a612** (fixer commit atop build 72a4003b1, base cac93e391; both cat-file verified,
author AND committer detain@interserver.net, zero trailers). Fixer diff = 4 files
(RulePathNudge.php, MemoryTool.php, StandingIntentTest.php, lane REPORT.md) — ALL inside the
BRIEF-declared set. No suite-figure / durations / dist / lang / README touches.

## VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 MINOR (step loop exits CLEAN)

## Round-1 MINORs — all three correctly fixed

- **MINOR-1 (fork true-bind)** — reload() (RulePathNudge.php:517-519) now branches on
  INTENT_MARK_PREFIX: intent marks look up `intentDigests[strip(key)]`, rule marks `digests[key]`.
  Hand-traced: with the fix the new pin's step-1 consult binds the `true` mark to digest(BEFORE),
  so the later edit (digest moves) re-announces; reverting the strip leaves the mark `true` through
  step 1 and the step-2 reload binds it to the NEW digest → suppressed → the pin's
  assertIsString reddens. Exactly discriminating. Rule-leg behavior unchanged
  (intentDigests raw-id keys could never match an absolute rule path). Pin
  testAForkReportedIntentReAnnouncesWhenItsTextMoves added; the :846-858 doc-block promise is now
  TRUE code, comment names the MINOR-1 provenance.
- **MINOR-2 (index heading vacuity)** — two pins added. Behavioral
  testTheIndexNeverClaimsAnArmedHeadingForTriggerlessDiskNotes drives the real tool save+view
  through a real (triggerless) store: no `## standing intents`, `[intent]` still visible, save
  reply free of "standing intent" (mutation-reason: unguarding the :358-360 armed sentence
  reddens it). Structural testTheIndexHeadingBlockIsPinnedForPresenceAndTriggerGate slices
  index()'s source and pins the heading literal (:295, count==1), the `if ($intents !== [])`
  gate (:294), the persisted-trigger guard (:283, byte-verified against source), and the
  indexLine() rendering leg (:297 vs :310's non-matching `);` — substr_count==1 holds). The
  class docblock's half-false "both legs are pinned" was replaced by the honest leg ledger.
- **MINOR-3 (forward-promise description)** — description() (:166-172) now says "an intent whose
  trigger its store actually holds rides …; the save reply says whether a saved note became
  armed" — mirrors the save-side truth-not-promise guard (:358-364, verified: armed sentence
  rides only the persisted-when locate()). ToolDocDrift green ⇒ generated doc surface agrees.

## NITs
- NIT-6 taken: budget-wide leg now asserts the envelope IS emitted (assertIsString) before
  bounding. NIT-4 (when-forgery pin / \n\r refusal in normalizeWhen) and NIT-5 (PSR-12 blank
  line, RulePathNudge.php:1064) left to weld — accepted as closeout suggestions per brief
  ("do not block on them"); neither is wrong-as-shipped code. The REPORT's NIT-5 justification
  ("src file outside round-1 scope") is inaccurate — the fixer DID touch that file — but the
  nit stands as a formatting one-liner for weld regardless.

## Gate re-derivation (worktree, linked, cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)
- php -l clean on all 5 lane files.
- StandingIntentTest: **21T / 90A OK** — matches fixer's corrected figure (18+3 new).
- --filter 'Nudge|Rule': **898T / 5002A OK** — byte-identical to r1; reload() change regresses nothing.
- guards ToolDocDrift|GlobDialect|DocFigureProseDrift|MemoryDocumentationDrift|DuplicatedTestHelperDrift:
  **133T / 4727A OK** — identical to r1's combined figure; new test literals carry no glob shapes.
- 'Memory' window: 709T + **6E/3F/1W**, ALL attributable foreign: MemoryPromptWiringTest 6E
  (InstructionDiffTracker null-$seen, proven in r1), DreamPassTest:288 + one 1F standing, and
  BootstrapSkillSkipsTest::testAOneShotRunReportsUnreadableMemoryNotesToo (child one-shot run
  exits 1) — the LAST is disclosed for the welder: the lane's BASE commit cac93e391's own
  message records "BootstrapSkillSkipsTest 3F … foreign standing proven by file-revert probe",
  so it predates the lane (it is green in the main tree only because master moved 2 batches on
  plus a live foreign WIP fix to InstructionDiffTracker). Neither the builder's nor r1's filter
  windows had covered it; not lane-caused, not lane-fixed — battery at batch close reproduces
  the foreign set, not this step's problem.
- "PHPUnit Deprecations: 2" = global, present at master too.

## Weld duties re-confirmed (unchanged, REPORT seams 1-5 with fixer's correction)
MemoryStore `when:` persistence hunk (seam #1 — the end-to-end gate), /memory heading +
host.memory.intents lang key + caller same commit (seam #2), durations.tsv StandingIntentTest
row, suite-figure re-pin now **+21T/+90A**, NIT-4/NIT-5 cures, C-CTX-3.e MEMORY.md union keep
intent-LAST.
