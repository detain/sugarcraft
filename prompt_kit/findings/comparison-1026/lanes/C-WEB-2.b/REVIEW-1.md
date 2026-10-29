# C-WEB-2.b REVIEW-1 (r1) — verifier at tip 5076c63c8 (parent 4aa9ea204; master aa38e06b1 has 4aa9ea204 as ancestor via 3cf70064f chain)

VERDICT: CLEAN — 0C/0M/0 step-local MINOR.

## Probes (all re-derived at tip, plain-pipe, cwd candy-code)
1. VERBATIM MOVE: old skillInvocationDraft body (git show 4aa9ea204:...Chat.php) vs SkillSlashInvocation::draft — line-by-line identical incl both comment blocks and the walk math; only deltas: `use BuiltInCommands` import, `\preg_match` lead-backslash, registry hoist becomes the `?SkillRegistry` param (null-check kept at the same position, before the walk). No semantic drift.
2. PARITY: same shared gate by construction; pinned behaviorally — SkillSlashInvocationTest::testTheSharedGateMatchesTheTuiDelegateByteForByte runs static draft + reflection-reached private Chat delegate over the whole corpus (both registry states). SlashSkillInvocationTest diff = 0 lines, green inside the 30T/143A combined filter. Ambiguous/multi-word/unregistered/disabled legs pinned both doors (gate + served files).
3. resolveMentions OFF: ServedSkillSlashTest::testASendThatDeclinesMentionsIsNotRewritten asserts the appended row content is byte-exactly '/shipit review' — non-vacuous (rewrite branch yields a different row; STARTED-vs-STARTED would be vacuous but the content assert carries it).
4. CHECKPOINT REWIND: admit() captures $draft=$text BEFORE the rewrite and rides it as admitPrompt arg3 → dispatch → TurnController::checkpointState ($turns, :2335) → inputBuf; test reads the REAL store checkpoint('s', idx) inputBuf === typed draft. TUI equivalent verified live: dispatchTurn passes $this->inputBuf (:10282), untouched by the local-$text rewrite — parity true.
5. CHAT DELEGATE: one-line return (src/Chat.php:11735-11738); refusal-notice {@see skillInvocationDraft()} cite at :11776 survives; docblock rewritten honestly ("the shared gate re-checks").
6. HUB DISCIPLINE: SessionHost single hunk @@1331..1353, strictly inside admit() (:1280, ends before admitExpanded :1373), positioned after resolveCustomCommand + command() + backend-null legs — precedence order pinned fail-loud by testAProjectCommandFileBeatsTheSkillOnTheServedRoute (slide-above mutation would rewrite /notes away from the command branch).

## Gates re-derived
- SkillSlashInvocationTest 8T/73A OK; ServedSkillSlashTest 7T/26A OK (15T/99A total, file-by-file exact; 240L/7-test ratio honest — disk-registry fixture + settleTurn harness, real submit() drives).
- SessionHost|Served: 235T/977A — builder claim EXACT.
- Guards battery (TreeWideGuardRoster|DuplicatedTestHelperDrift|GlobDialect|StderrEmitterCensus|ReadmeRosterDrift|EnvRoster): 188T/65452A, Failures: 2 — BOTH are the TreeWideGuardRoster pair accusing ONLY Cli/LaunchFlagsTest scandir + Host/SessionHubTest globs (batch-8 files, untouched by this 6-file diff); master-side cure 978ff2889 exists and its own message records the same battery green with the licences — foreign-inherited claim CONFIRMED.
- Builder's "80T/249A families / 154T/25914A" filter strings not exactly reconstructible; every substantive claim they stand for re-derived true above.
- Tree clean; author/committer both detain@interserver.net; zero trailers.

## Laws
- Scope: exactly the 6 brief files; no durations.tsv/suite-figure.json/dist (welder carry: DUR x2 rows, +15T re-pin — staleness pair red at tip with base-carried gap 25537-vs-27684, +15T rides it, correct for a lane-forbidden file). SKILLS.md hunk at :642 (brief's ~:578 drifted) — same declared section, in-scope.
- E646: no src timers; the 15s settle guard is test-side only. No new proc_open/fork; tearDown exec('rm -rf') matches the tests/Host house pattern (3 sibling precedents).
- final class, strict_types, one type/file, no user strings in the gate (i18n N/A), static pure-gate shape mirrors BangShell::commandOf precedent.

## Welder carries (confirmed owed, not lane defects)
DUR x2 rows; +15T figure carry; §4 cite 5076c63c8; drop the foreign TreeWideGuardRoster note on rebase-merge over 978ff2889 (pick will land clean — guard file not in the diff).
