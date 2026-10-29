# C-EXT-3.a — `/skills reload` + SkillRosterDiffTracker (turn-context tail)

VERDICT: COMPLETE. Single commit on `cc/C-EXT-3.a` (base e91eb1d47). Targeted net gate
740T/35,923A with ONLY two failures, both proven inherited base-reds (below).

## What landed

- `SkillRegistry::replaceAll(array $skills): void` — swaps the discovery set in place,
  KEEPS `$disabledSkills` (operator veto), re-derives `$unavailable` through the
  `register()` requirements door. Object identity survives, so the engine, the
  workspace and every App share the swap with no re-threading.
- NEW `SkillRosterDiffTracker` (`src/Skills/`) — the roster sibling of
  `Context\InstructionDiffTracker` (CTX-3). `capture(SkillRegistry)` freezes
  name→hash('xxh128', description) over the listing population (enabled AND
  auto-invocable); `recheck(): string` is PURE — frozen-vs-current every call,
  '' when nothing moved; rows `- added:` / `- removed:` / `- changed:` using the
  same `SkillPromptLine` text the listing would print; bounded at
  MAX_ROWS_PER_CLASS=8 per class (+`+N more (not shown)`) and MAX_TOTAL_BYTES=4096
  (+`(remainder omitted: N rows)`); every payload through `PromptFence::escape`.
- `TurnContextBlock` — `skillRosterDiff` ctor field + `withSkillRosterDiff()` +
  accessor; render() section directly behind the instruction diff,
  `escapeOwnFence`-neutralised; WHAT IT CARRIES updated. (No positional
  `new TurnContextBlock(` callers existed; verified repo-wide.)
- `Runtime` — `turnContext()` chain gains `->withSkillRosterDiff($this->skillRosterDiff($app))`;
  private `skillRosterDiff()`/`skillRosterTracker()` mirror the instructionDiff
  trio, slot `'skill-roster-diffs:'.spl_object_id($app->availableSkills)`
  (one spelling, shared stamp+read); STAMP LINE `$this->skillRosterTracker($app);`
  in `systemPromptSections()` directly after the `listForPrompt()` listing —
  `primeSessionPrompt()` (Runtime:6157) runs the whole walk parent-side in
  `EngineBackend::beginSessionTurn` :3062/:4685, so the baseline is captured in
  the process that outlives the fork and every child inherits it (C-CTX-1 law);
  `recheck()` being pure means a child's recheck can never stain the parent slot.
- `/skills reload` arm in `SkillsHostCommand` (TUI + serve: `Chat.php:12585`
  delegates `/skills` to this class via `runHostCommand`; `WorkspaceContext->skills`
  IS the engine registry per `Bootstrap::workspace()` — comment :1904 — so serve
  parity and TUI inheritance hold by identity). Refuses loudly with
  `host.skills.reload.no_live` when the session has no live registry;
  extra-arg form → usage. Reply `host.skills.reload.done` counts
  added/removed (names(), raw) + live total.
- `lang/en.php` — `host.skills.reload.done`/`.no_live`, usage + `cmd.skills.hint`
  gained `reload`; `docs/COMMANDS.md` regenerated via
  `php tools/gen-command-docs.php --write` (table row :400) + hand prose sentence;
  `docs/SKILLS.md` new section "Reloading discovery without a relaunch"
  (swap law + announce law), placed before the auto-matching-dormant section.

## Gates (cwd candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)

- `SkillRosterDiffTrackerTest` (NEW): 13T/35A OK.
- `SkillRegistryTest`: 51T/103A OK (48T/92A at base; +3 replaceAll tests).
- `TurnContextBlockTest`: 13T/59A OK (+3).
- `SkillsReloadTest` (NEW): 5T/25A OK.
- `PromptPrefixByteStabilityTest`: 8T/84A OK (+1 leg; all 7 existing legs green).
- Combined net gate (22-family filter incl. ChatHostLangCatalogue, RegistryTranslation,
  CommandDocs, DocFigure, ClaimFamilies, ReadmeRoster, GlobDialect,
  DuplicatedTestHelper, TreeWideGuardRoster, StderrEmitterCensus,
  OneSidedHomeSandbox, SuiteSkipRoster, KeyBinding): 740T/35,923A/2F/2-deprecations.
  'PHPUnit Deprecations: 2' is the PRE-EXISTING class-load baseline (main tree shows
  the identical pair; the two new files' own runs show them too at 0-fail).
- `php tools/gen-command-docs.php --check`: "command docs: up to date", rc 0.

## Inherited base-reds (NOT this lane — weld carries)

1. `OneSidedHomeSandboxTest::testNoTestFileSandboxesOnlyHalfOfHome` — roster gained
   `Backend/CacheReusingSummaryBackendTest.php` (53b8e0fbc, C-CTX-5.a) and
   `Context/InstructionWalkPastRootTest.php` (d361720d9, C-CTX-4.c) without rows;
   both files exist at base e91eb1d47 and the roster has 0 of them at base
   (`git show e91eb1d47:...OneSidedHomeSandboxTest.php | grep -c` → 0). Guard text
   forbids adding rows (fix the files); owning lanes/weld owe it.
2. `ChatHostLangCatalogueTest::testEveryCatalogueKeyIsAskedForByChatOrHost` — 6 orphan
   `chat.*` keys (startup.personal, attach.pdf_pages, effort.launch_unshaped,
   permission.not_persisted, permission.persisted, serviceTier.launch_unshaped);
   NONE is a host.skills key (mine pass the asked-for census); identical red re-run
   in the main tree /home/sites/sugarcraft/candy-code this session. Their askers
   sit outside the guard's scanned set (e.g. src/Permissions/SavedRules.php) as of base.

## Mutation proofs (cp-backups /tmp/opencode/cc-cmp-C-EXT-3-a/mut/, md5-verified restore each)

- M1 replaceAll also clears `disabledSkills` → exactly
  `SkillRegistryTest::testReplaceAllCarriesTheDisabledSetAcrossTheSwap` +
  `SkillsReloadTest::testDisabledSkillsStayDisabledAcrossReload` red (2F).
- M2 render() drops `escapeOwnFence` on the section → exactly the fence-payload test red (1F).
- M3 `recheck()` returns '' always → 10F: all delta-shape tracker tests +
  `PromptPrefixByteStabilityTest::testAMidSessionSkillRosterSwap…` (the
  ''-when-still/header/determinism pins correctly stay green).
- M4 reload arity guard broken (`< 0` — arm never fires) → the 4 functional reload
  tests red; M4b guard removed (always reloads) → exactly
  `testReloadWithAnArgumentIsToldTheUsage` red. (Both directions named.)
- M5 MAX_ROWS_PER_CLASS 8→2 → bound test + byte-budget test red (2F).
- M6 removed-row loses `PromptFence::escape` → exactly `testHostileFenceSpellingsArriveEscaped` red.
- M7 en.php hint drift (strip `|reload`) → `CommandDocsDriftTest`
  `testTheGeneratedDocsAreUpToDate` + `testTheCheckModeAgreesWithTheTest` red —
  pins the committed docs-pair (§1.8 law).

## Deviations (disclosed)

1. **Stamp-line region stretch**: the design block declared Runtime's
   "turnContext + new skillRosterDiff"; the one-line `$this->skillRosterTracker($app);`
   stamp sits in `systemPromptSections()` beside the listing. Needed so the capture
   lands parent-side through the ALREADY-running `primeSessionPrompt()` (the
   `primeInstructionDiff` precedent) without editing EngineBackend (outside the
   file list). No cheap in-process mutation reddens the suite for removing it
   (same-process lazy capture answers identically) — its guarantee is the fork-path
   ordering C-CTX-1 established; honest disclosure, not fabricated as mutation-proven.
2. **Prefix DoD re-worded**: the `<available-skills>` listing is
   `Stability::PerTurn` assembled OUTSIDE the standing-slab memo (closure ends
   Runtime :5076; listing :5161; Stability.php says the skill layers are
   "re-derived per App"), so the swapped roster DOES appear in the next turn's
   prefix — as it already does for shipped `/skills enable|disable` (C-EXT-2.d).
   The new stability leg asserts the honest law: the TRACKER contributes zero
   prefix bytes (its HEADER never appears in either turn's systemPrompt), the
   instruction slab and every earlier message row stay byte-identical, the delta
   rides the tail, and the new roster line is pinned present (PerTurn law kept).
   Freezing the listing was rejected: it would keep advertising disabled/removed
   skills in the prompt — a correctness regression.
3. **Pure recheck**: no stat-gate/`pending` replay (a name→hash map over a live
   object needs neither); determinism follows by construction — deviation from
   InstructionDiffTracker's internals, contract preserved.
4. **Population**: body-carrying enabled skills are NOT excluded from the
   fingerprint the way `listForPrompt()` hides them from the line roster — a
   reload's adds/removals/changes must announce even for body-channel skills
   (rationale in the class docblock).
5. `SkillRosterDiffTracker`'s own file header names the tracker a session-frozen
   baseline; per (2), "frozen" means the memo-slot generation, not the prefix slab.

## Weld carries

- DUR rows owed (new test files): `tests/Skills/SkillRosterDiffTrackerTest.php`,
  `tests/Host/Commands/SkillsReloadTest.php`.
- Suite figure drift: +21T net this lane (tracker 13 + reload 5 + registry 3 +
  turn-context 3 + stability leg 1 = 25 new methods; minus none removed).
- The two inherited reds above for their owning lanes / weld.
- BRIEF.md in this lane directory stays untracked (only REPORT.md committed).
