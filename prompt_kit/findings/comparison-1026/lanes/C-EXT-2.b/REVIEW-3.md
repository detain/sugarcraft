# C-EXT-2.b — round-3 code review (REVIEW-3)

Reviewer: cc-cmp step reviewer (fresh session, disk-only). Tree: /home/sites/cc-cmp-C-EXT-2.b,
branch cc/C-EXT-2.b. Plan block: plan_comparison.md:1033-1043 (W8); R-EXT-1 ruling at :4124.
NOTE: REVIEW-1.md is ABSENT on disk (only BRIEF.md, REPORT.md, REVIEW-2.md exist); its MINOR text
was taken from the brief's quotation and the REVIEW-2 record.

## Phantom round-2 story — confirmed
- `git log master..HEAD` = exactly ONE commit: 8ef5643d86352b504d2afe6e5d0d2b85fca84c15
  "C-EXT-2.b: active skill effects honour allowed-tools, model and effort" on base ebf05989e.
- 417385a64 and 61d297731 do not exist (`git cat-file -e` fails; no branch/dangling object).
- The lane's REPORT.md itself records both round-2 fixer passes as VOIDED with no commit — the
  honest outcome. Reviewing 8ef5643d8 as the sole deliverable.

## AXIS 1 — the r1 MINOR: **VOID**
Claim: ActiveSkillEffects.php:129 docblock "names maxBytes while maxAllowed counts shared per-root
readBudget entries incl dedup/missing children". Verified against disk:
- `grep -in 'maxBytes|maxAllowed|readBudget|budget' src/Skills/ActiveSkillEffects.php` → 0 hits
  (whole-file, case-insensitive).
- Line 129 at 8ef5643d8 is `foreach (self::rulesFrom($skill->disallowedTools, PermissionAction::Deny) as $rule) {`
  — inside derive()'s allow/deny rule loop, no docblock, nothing budget-shaped.
- Repo-wide: `maxBytes` appears ONLY in untouched src/Context/ files (RulePathNudge, EnvironmentBlock,
  MemoryBlock); `MAX_TRACKED_CHILDREN` matches nothing in this tree at all (InstructionDiffTracker has
  budget *prose* but no such constant); `maxAllowed` only as unrelated locals in wall-clock timing tests.
Conclusion: the r1 MINOR text does not describe this lane's code — it is cross-labeled from the
SkillPathNudge/Context read-budget shape (C-CTX-1.c territory). The FIXER adjudication is TRUE.
**The r1 MINOR is VOID; the weld gate may treat it as closed with zero code change.**

## AXIS 2 — R-EXT-1 ruling fidelity: PASS
- ActiveSkillEffects (final readonly, no state): derive() slices the active turn at the newest real
  UserMessage (turn-context rows do NOT end the turn), merges SkillTool::invokedIn (most-recent-first)
  with the re-injected invokedSkillsIn roster, resolves registry key → displayName leaf, builds rules
  via PermissionRule::fromClaudePrefixDialect (malformed patterns skipped, rest survives);
  model/effort take the LATEST declaring skill field-by-field (`??=` over most-recent-first).
- PermissionGate::withTurnGrants: typed allow/deny lists validated (InvalidArgumentException both arms),
  clone-set, carried across withMode. decide() order verified end-to-end: rm-rf breaker first (untouched),
  configured rules first, turn DENY fires before mode (incl. bypass) only when no configured answer
  stands; turn ALLOW answers mode-Ask only, never lifts configured Deny/forced asks. As-written
  first-match — no memo rewrite. R-EXT-1 "as written, all tiers" is STRUCTURAL: no tier branch exists
  anywhere in ActiveSkillEffects/PermissionGate grant paths (grep: only imports/docblock mentions).
- Wiring: Runtime::run() re-derives per step → model/effort ride `$effects->x ?? $app->x`; Chat::gateToolCall
  applies tool grants only (documented). Hook-swap clears via withTurnGrants([],[]) on fresh prompts;
  no churn when effects empty AND no grants (assertSame pin). No literal try/finally restore-on-except —
  consistent with the mode-swap precedent and the stateless re-derive law (every step/dispatch
  re-derives); brief never mandated it. Noted, not a defect.

## AXIS 3 — INERT flip + docs: PASS
4 SKILL rows (effort/allowed-tools) removed from FrontmatterKeyAudit::INERT ('context' stays);
InertFrontmatterDocumentationDriftTest is dynamic (derives '**Inert**' doc rows vs array_keys(INERT))
so doc+roster flip coherently — needed no edit, brief-listed but correctly untouched.
FrontmatterKeyAuditTest re-fenced same-commit. SKILLS.md rows flipped Inert→Live with honest readers;
diagnostics example count fixed; README bullet flipped.

## AXIS 4 — built-in trims: PASS
Trimmed (declare as-written lists): composer-wizard (adds scoped Bash(composer:*)),
php-best-practices, phpunit-master, security-audit. api-design, laravel, symfony, testing-strategies
declare nothing — no trim needed; "no Bash pre-approval unless the skill runs shell" holds
(composer-wizard's body drives composer via Bash). BuiltInSkillsTest pins all 8 (4 lists + 4 null,
effort null). Brief's "8 trims" vs 4 real declarations: deviation honestly disclosed in REPORT.

## AXIS 5 — tests non-vacuous: PASS
PermissionGateTurnGrantsTest 14T (deny-leg under AcceptEdits/DontAsk/Bypass, cannot overtake
configured Allow; allow-leg answers Ask only, never lifts Deny/breaker; arg-scoped rm* vs ls; polarity;
validation throws; withMode carry; base-gate negative control). RuntimeSkillEffectsTest 5T (real
provider/request via onRequest seam: model+effort rewrite at step, grant speaks at step2, fresh prompt
clears all three + assertSame no-churn, model-only leaves effort, roster re-injection alone).
ActiveSkillEffectsTest 16T (turn boundary, failed-call grants nothing, asset-read not invocation,
unknown-name skip, display-leaf, dialect rewrite, malformed skip, latest-declaring order pin,
apply carry/clear/no-churn/no-hook). Each key arm reddens under its removal (mutation-reasoned).
DUR duty: 3 new files with measured rows listed in REPORT.md for the welder.

## AXIS 6 — gates (plain pipe, cwd worktree/candy-code, env -u LINES -u COLUMNS): PASS
- --filter 'Skill|PermissionGate|RuntimeSkillEffects|ActiveSkillEffects':
  `Tests: 1553, Assertions: 8401` rc=0 (0F). (Broader than 578T because 'Skill|' is a union filter.)
- --filter 'Inert|Frontmatter': `Tests: 183, Assertions: 776` rc=0 (0F).
- five-guard: `Tests: 133, Assertions: 15781, Failures: 2` — EXACTLY the staleness pair,
  both "Failed asserting that 25537 is identical to 26238" (ReadmeSuiteFigureDriftTest :93 + artifact arm).
  Welder re-pin duty, forbidden lane files untouched by the commit. Byte-matches REVIEW-2's fingerprint.
- §1.9 literals: no 'Refused'-shaped strings; wildcard-bearing literals are paren-exempt Bash(...)
  forms; GlobDialect corpus unaffected (guard green above).

## AXIS 7 — laws: PASS
Single commit, %ae and %ce both detain@interserver.net (separate greps), zero Co-Authored trailers;
porcelain 0; suite-figure.json/durations.tsv/dist untouched; diff = 22 files, all within declared
scope + disclosed in-step extras (SkillRegistry, SkillTest, BootstrapLaunchNoticeRoutingTest —
consequences of the effort-signature and INERT flips; REPORT-deviated, REVIEW-2-ruled PASS).

## NITs (non-blocking)
- N1: no exception-path hook-restore pin; design converges by re-derive, matches mode-swap precedent.
- N2: REVIEW-1.md missing from the lane dir (record gap, not a code finding).

VERDICT: CLEAN
