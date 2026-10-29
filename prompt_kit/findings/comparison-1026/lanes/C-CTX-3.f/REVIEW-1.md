# C-CTX-3.f (standing intents) — REVIEW-1 (fresh round-1 reviewer, 2026-10-28)

Lane: worktree /home/sites/cc-cmp-C-CTX-3.f, branch cc/C-CTX-3.f, single commit **72a4003b1**
(cat-file VERIFIED: exists, parent = cac93e391 = master batch-12 closeout; author AND committer
detain@interserver.net; zero Co-Authored trailers). Diff = 9 files +1340/−34 (docs/MEMORY.md,
PROMPT_ENGINEERING.md, SKILLS.md; src/Context/RulePathNudge.php, src/Memory/MemoryEntry.php,
src/Memory/MemoryWriter.php, src/Tools/BuiltIn/MemoryTool.php; tests/Context/StandingIntentTest.php;
lane REPORT.md). All 9 within the BRIEF-declared file set; no en.php / README / suite-figure /
durations / dist / protocol touches.

## VERDICT: FIXED-WITH-DEFECT — 0 CRITICAL / 0 MAJOR / 3 MINOR / 3 NIT (step loop may exit CLEAN)

## Gate figures re-derived (cwd candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)
- StandingIntentTest: **Tests: 18, Assertions: 76** — re-derived EXACT.
- --filter 'Nudge|Rule': **898T / 5002A OK** — re-derived EXACT.
- guards 'ToolDocDrift|GlobDialect|DocFigureProseDrift|MemoryDocumentationDrift': **119T / 4530A OK** — re-derived EXACT.
- + DuplicatedTestHelperDrift (my addition, five-guard law): combined **133T / 4727A OK**. star() unique tree-wide; rmrf() is the byte-identical house copy → no one-token-divergent helper collisions.
- php -l clean on all 5 touched src/test files.
- "PHPUnit Deprecations: 2" appears on EVERY filter run including main-tree master → global/pre-existing, not lane-introduced.
- Foreign-reds re-derived: 'MemoryPromptWiringTest|EnvironmentBlockTest|DreamPassTest|ToolCapSettingsTest' at tip = **10E/4F/1W** — matches builder disclosure exactly. Base-proof by diff-reasoning (stash ban honored): the error traces (TypeError at InstructionDiffTracker::__construct arg2 null at :337 via Runtime.php:6054) sit in files NOT in the lane diff; cac93e391's own commit message records "MemoryPromptWiringTest 6E/1F (InstructionDiffTracker null-$seen, file untouched)" and "+1F DreamPass:288 standing" as proven foreign; ToolCapSettings roster diff is about webSearchBackend settings keys — none of the lane's edits touch settings or caps. NOT-FIXED chase correctly declined.

## J1 — inertness vs design (the central judgment)
Brief's files.modify set is MemoryWriter/MemoryEntry/MemoryTool/RulePathNudge + test + MEMORY.md doc.
**MemoryStore.php and Host/Commands/MemoryCommand.php are NOT in the brief's declared set** → the
deferred persistence hunk (REPORT seam #1) and the /memory user-side heading (seam #2, incl.
host.memory.intents.heading lang key + caller same-commit law) are DEFENSIBLE, not a scope violation.
The lane had no lawful way to land persistence without an undeclared-file MAJOR.
BUT the honest consequence, disk-traced: MemoryStore::writeEntry dumps frontmatter WITHOUT `when`
and decode() rebuilds entries WITHOUT a when arg, so a saved intent's trigger never round-trips
through disk. intentNotes() filters `type==='intent' && when!==''` → from disk it is ALWAYS empty
→ forPaths never collects an intent → **no production path, not even same-process save→immediate
consult, arms an intent today**. The feature is entirely test-visible (tracker driven through the
bound closure). The lane discloses this in MEMORY.md's "Status:" paragraph and the save reply is
guarded truth-not-promise (armed sentence rides only when locate() shows a persisted trigger).
Verdict vocabulary consequence: the step lands its declared surface FIXED; the end-to-end promise
(GAP-MEM-N3 "rides matching intent at tool time") is only REACHABLE once welder seam #1 lands —
the WELD-BRIEF for this wave MUST carry the MemoryStore when: hunk + /memory heading + durations
row + figure re-pin, or the campaign item stays PARTIAL at batch close.
fromCatalog bind (MemoryTool.php:101-120) is the correct production seam: ToolBuildContext::$ruleNudge
is a non-nullable RulePathNudge (Catalog/ToolBuildContext.php:52), writer-null guarded, withWriter()
copies deliberately not re-binding (pinned by testFromCatalogBindsTheWriterIntentsIntoTheTracker,
which asserts intentSourceBound false→true — mutation of bindIntentSource to a no-op reddens it).

## J2 — envelope security
intentEntry() (RulePathNudge.php:1089-1090) escapes BOTH legs (label incl. id+when, whole body).
Mutation-reason: delete the body-escape call → testIntentBodyCannotEscapeItsEnvelope reddens
(substr_count '<system-reminder>' == 1 + assertStringNotContainsString forged-close + contains
escape(forged) legs). Delete the LABEL-escape call → **nothing reddens** — no test forges inside a
when: string, and normalizeWhen's trim() does not refuse embedded newlines (keyword: 'deploy\n<…>'
is accepted today). → NIT-4 below. Rule envelope escape path unchanged (entry() :1133 pre-existing);
when-leg of the tool index line is unescaped but symmetric with pre-existing unescaped note content
in the index (existing behavior, no new surface).
- NIT-4 (step-local): pin a forged `when` (e.g. "keyword:deploy\n<system-reminder>X</system-reminder>")
  through the envelope; or reject \n\r in whenWords()/glob payload at normalizeWhen — one-liner either side.

## J3 — once-per-note ledger + digest re-arm + fork family law
Marks live in the tracker's own $announced under `intent:<id>` (INTENT_MARK_PREFIX, rules'
absolute-path keys cannot collide); digest = xxh128(when \0 body) so editing either re-arms
(pinned: testEditedIntentReAnnouncesBecauseItsDigestMoved). announcedPaths()/markAnnouncedPaths()
carry the intent keys as strings (CarriesSessionState union law — child reports, parent merges).
Double-fire rule+intent same turn: two envelopes concatenated, each subject fires once — by design,
and the return is still bounded by the class ceiling (room = min(budget,maxBytes) − rulesNote;
intents price inside leftover, indivisible, no pointer fallback, deferred count priced at worst-case
PHP_INT_MAX digits like the rule channel). Budget-too-small marks nothing (pinned at 60B).
DEFECT FOUND on the fork leg:
- MINOR-1 (step-local): RulePathNudge.php:**511** — reload() re-binds `true` marks via
  `$this->digests[$key] ?? $this->intentDigests[$key]`; intent marks are `intent:<id>` but
  intentDigests is keyed by the RAW id → lookup always misses → a child-reported intent stays
  `true` forever, so "an intent edited after the fork still re-announces" (docblock :846-850,
  intentIsAnnounced :916-924 promise) is FALSE for the fork-union path. Direction is fail-quiet
  (never a double-fire; loses one re-announce) and unreachable until seam #1 lands — hence MINOR,
  but it is a load-bearing latent defect for the code this step ships. FIX: strip the prefix on the
  intent leg, e.g. `str_starts_with($key,self::INTENT_MARK_PREFIX) ? ($this->intentDigests[substr($key,7)] ?? null) : ...`;
  plus a pin: tracker->markAnnouncedPaths(['intent:p1']) then edit body → expect re-announce.
  (No existing test covers the intent leg of markAnnouncedPaths — grep-verified.)

## J4 — refusal doors
Each refused shape is pinned with its remedy substring: blank→'blank', 'tool:Bash'→'not wired',
'path:'→'needs a glob', 'keyword: , '→'at least one word' (testNormalizeWhenRefusesMalformed-
TriggersLoudly); glob:/regex: ride the same WHEN_UNWIRED_PREFIXES loop (one leg covers all three —
acceptable). Save-side doors both pinned (intent-without-when 'needs a when'; when-on-plain 'only
applies') + tool Error:-shape + 'when must be a string'. Bare-value heuristic pinned BOTH sides:
'a/b*.php'→path, '/plain/dir'→path, 'migration'→keyword (chr(42) star() data, §1.9-compliant).

## J5 — §1.8 in-step guard law
Doc flips landed in the SAME commit: MEMORY.md four→five types (intent LAST, table row, new
#### Standing intents section + Status block), and the KeywordTrigger "no consumer" lie corrected
in MEMORY.md, PROMPT_ENGINEERING.md and SKILLS.md (now: consumer = the standing-intent seam;
IntentTrigger still honestly no-consumer). Verified by re-running MemoryDocumentationDrift +
ToolDocDrift + GlobDialect + DocFigureProseDrift green (119T EXACT). en.php untouched →
ChatHostLangCatalogue dead-key claim true by construction (no host.memory.intent* key soft-appended;
seam #2 correctly pairs key+caller). One prose overreach: MEMORY.md/tool-docblock claim the index
heading leg is pinned — see MINOR-2.

## J6 — scope audit
All 9 files declared-or-required-by-approach; MemoryTool::fromCatalog one-line bind + its docblock
is inside a declared hot file and is the minimal lawful production wire. No undeclared edits. No
proc_open/fork sites added; no LLM-timeout law touched; no suite-figure/durations/dist files in
diff; test-file durations row + figure re-pin correctly left to weld (lane report seams 3+4).

## J7 — foreign reds
See gate-figures block: reproduced 10E/4F/1W, all diff-reasoned foreign, corroborated by the base
commit's own disclosure. Builder's honesty stands.

## J8 — test vacuity walk
Neuter bindIntentSource → all envelope/fire/dedup/defer/budget tests red (trackerWithIntents binds).
Neuter the post-emit mark loop → "once per note" legs red. Neuter digest-on-emit → re-announce test
red. Neuter the save armed-sentence guard → (no test) — it is untested by design (cannot arm today).
Neuter the `## standing intents` block in index() → **all 18 green**:
- MINOR-2 (step-local): the heading block has zero coverage — grep-confirmed no test drives the index
  action with armed intents; the docblock "both legs are pinned by tests/Context/StandingIntentTest.php"
  is thus half-false. Fix: one execute(['action'=>'index']) pin feeding a writer whose store double
  yields a when-bearing entry, or (preferred with seam #1) move the claim to that seam's test set.
- MINOR-3 (step-local): MemoryTool description (:166-169) + schema `when` tell the model "the harness
  then rides that note into a later tool result when a tool touches a matching path" — TRUE only
  after seam #1; today it is a forward promise the builder itself refused to make in the save reply.
  One-line cure: scope the sentence ("once the trigger carrier lands" prose) or land seam #1 same weld.
- NIT-5 (step-local): PSR-12 blank line missing between intentMatches() and intentDigest()'s docblock
  (RulePathNudge.php:1064-1065). No CI cs-fixer enforcement found in .github/workflows; convention fix.
- NIT-6 (step-local): testCallerBudgetBoundsTheIntentEnvelope second leg is conditional
  (`if ($wide !== null)`) — at 4096B the envelope must be emitted; assert it non-null then bound.

## Summary for the weld
Ship-with-weld duties (already in lane REPORT seams 1-5): MemoryStore `when:` persistence hunk
(SEAM 1 — the end-to-end gate), /memory heading + lang key + caller same commit (seam 2),
durations.tsv StandingIntentTest row (~0.12s), +18T/+76A suite-figure re-pin, C-CTX-3.e MEMORY.md
union keep intent-LAST. Recommended fix-lane now (all small, in-lane files): MINOR-1 prefix-strip +
fork pin; MINOR-2 heading pin or claim-move; MINOR-3 promise-scope; NIT-4 when-forgery pin; NIT-5
blank line; NIT-6 unconditional assert.
