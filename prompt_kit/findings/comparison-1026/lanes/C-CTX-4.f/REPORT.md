# C-CTX-4.f — DONE, commit 9e47c4366 (branch cc/C-CTX-4.f, base e306bf4fc, never pushed)

User-tier `references: [{path,name}]` shipped end-to-end: schema def (ToolSettings,
SettingType::Json, RiskClass::Security, layered, NO withProjectSettable) → validated
launch feed `LaunchContext::referenceReadRoots()` (missing/non-dir/unusable →
LaunchNotices::record; inside-session-root → refused-as-redundant; relative rebased
on root; dedupe) → `ToolBuildContext::$readRoots` → Read/Grep/Glob/Ls jail fallbacks
(absolute-path-only, PathJail statics; Grep/Glob/Ls anchor IgnoreRules + walk filters
to the matched reference root; Glob withinRoot() now multi-root). Write/Edit/Bash/
worktree jail unchanged (structural pin: Write has no readRoots param).
EnvironmentBlock::withSettings() advertises survivors in the static <env> half —
header + one line per ref + capped RepoMapBlock summary, names/paths flattened,
byte-capped (2048/120) and PromptFence-escaped; identity early-return extended;
withVolatile(false) keeps it; renders byte-stable (pinned).

Doc rows same commit: gen-settings-doc --write (LAYERED_KEYS + LAYERED count 138,
SETTINGS.md row + restart roster seventy-seven, README settings:layered), README
never-project enumeration gained a `references` clause (TrustKey guard green),
LayeredSettings.php prose, PERMISSIONS.md "Read-only reference roots" section,
EnvironmentBlock docblock truth-flip (the "deliberately no additional working
directories" paragraph is now historical).

Gates (targeted, cwd=candy-code, plain pipe): final consolidated filter
1098T/221,491A rc=0 incl ProjectReferences (13T/68A), GlobTest, LayeredSettingsTest,
SettingsSchemaDocDrift, ReadPathCensus, DocFigure, GlobDialect (respelled one new
'<?php\n' fixture literal to reuse the existing '<?php' — corpus figure holds at
309,132), TrustKey, EnvRoster/ReadmeRoster, StderrEmitterCensus, LaunchNoticeCap,
KeyBindingDrift, ConfigWriteProducerDrift, Duplicated*/SymbolCitation families.

In-step roster syncs: LayeredSettingsTest userTierOnly literal + 'references' AND
+'webSearchBackend' (latter base-stale; proven red at e306bf4fc, file is lane-owned).

Base-inherited reds NOT touched (all re-proven red in a scratch worktree at
e306bf4fc): InstructionDiffTracker::capture() passes uninitialized $seen → null
TypeError when no instruction docs are tracked — reddens 4 EnvironmentBlockTest
assembled-prompt tests + SystemPromptWiringTest::testEnvironmentBlockReachesTheProviderSystemPrompt
(owner/closing-lane fix is a one-line `$seen = [];`); PermissionsIntroDriftTest
roster offsets; OneSidedHomeSandboxTest roster missing two later-merged files;
SwallowingCatchCensus offender tests/Cli/MainWorktreeTest.php.
Global pre-existing: "PHPUnit Deprecations: 2" on every run (config-level, untouched).

Welder: NEW test file candy-code/tests/Context/ProjectReferencesTest.php needs a
durations.tsv row; GlobTest.php/LayeredSettingsTest.php modified in place.
Deviations disclosed: ghost-path-under-reference answers "Error reading file"
(not the jail message) because PathJail admits missing children whose parent is
inside — pinned honestly in testAGhostPathUnderAReferenceReadsAsMissingNotJailed.

## FIX ROUND 1 (review-1 MINOR fixed; both NITs carried)

- MINOR done: `EnvironmentBlock::normalizeReferences()` now mirrors the redundant leg of
  `LaunchContext::referenceReadRoots()` — a reference that realpaths inside `realpath($this->cwd)`
  (or equal to it) is silently dropped, so in-root refs are never advertised in `<env>` while the
  shelf withholds them. `$rootReal` computed once per call; the leg sits between the missing and
  duplicate legs, matching feed order; compared against realpath-of-root (not the raw cwd) per the
  symlinked-checkout law. `SETTING_REFERENCES` const docblock and the `normalizeReferences()`
  prose truth-fixed: the "same shape check" claim now enumerates all four legs (unusable, missing,
  duplicate, inside-the-root) and states why the mirror exists — true after the change, the
  review-1 overclaim is gone.
- Pin: `ProjectReferencesTest::testAReferenceInsideTheSessionRootIsNeitherAdvertisedNorShelved` —
  `{path: 'inner'}` under cwd renders `<env>` byte-identical to the no-references shape (frozen
  $now/$platform ride `withSettings()`, so the equality is byte, not second, exact) AND the launch
  feed keeps the real reference, announces the redundant one, and is not refused.
  Class re-derived: 13T/68A → 14T/73A OK.
- Mutation-reasoning: neutering the skip leg (`false && …`) reddens exactly the new pin
  (advertised 'Project references … inside-me' diff shown); cp+md5 restore clean.
- Gates (cwd=candy-code, plain pipe, -c phpunit.xml): `tests/Context` 1370T with only the 4
  disclosed base-family `InstructionDiffTracker` $seen TypeErrors (re-proved identical at HEAD with
  this round's files removed — inherited, not this step's);
  `ReadPathCensus|SettingsSchemaDocDrift|LaunchContext` 129T OK; guard family
  (`SymbolCitationDrift|Duplicated*|StderrEmitterCensus|DocFigureProseDrift|GlobDialect|ReadmeSuiteFigureDrift`)
  232T with only the expected figure-staleness pair (live 27,872 vs pinned 25,537 — welder re-pin;
  durations/suite-figure carry: the step's new-test figure was +13T, it is +14T now).

### Carries
- NIT-1 (not fixed here, per brief): the absolute-path predicate is duplicated 4× —
  `Read::isAbsolutePath`, `Grep::pathLooksAbsolute`, `Ls::denotesAbsolutePlace`,
  `Glob::absoluteShape` — consolidation to `PathJail::isAbsolute` is cross-file work in hot tool
  files, out of this step.
- NIT-2 (not fixed here, per brief): `oneLine()`'s substr byte-cap can split a multibyte char
  before the '…' — same shape as the house cap family; disclose only.
