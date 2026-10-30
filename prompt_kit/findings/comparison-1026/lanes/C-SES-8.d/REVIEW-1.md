# C-SES-8.d — `/insights` wiring — REVIEW-1 (round 1, fresh read-only reviewer)

Object: cc/C-SES-8.d @ 38f097f4b (1 commit off base 410f5ac58); master tip at review 07e49e872.
Verdict: FINDINGS — 0 CRITICAL / 1 MAJOR (weld-carry, not a lane defect) / 1 MINOR (step-local) / 2 NIT.

## Verified on disk
- `git diff --name-status master...HEAD`: 8 files — M README.md, **A** builtin-commands/2215-insights.php,
  M docs/ARCHITECTURE.md, M docs/COMMANDS.md, M lang/en.php, M src/Chat.php,
  M tests/Chat/PaletteSupersetDispatchTest.php, M tests/Commands/MenuBarLabelDerivationTest.php.
- **Builder's "no new *Test.php" claim is TRUE** (both test files are M, not A) → no durations.tsv row owed,
  no suite-figure delta from this lane. Its "8 files" and lang-slot claims also check out.
- Production reachability CONFIRMED (not dormant): `BuiltInCommands` scans `specDir()` = package-root
  `builtin-commands/` (src/Commands/Specs/BuiltInCommands.php:61) and `Chat::dispatchCommand()` is
  table-driven — `forSpelling($parsed->name)` → `accepts()` → `$this->{$handler}($text)` for
  CommandArguments::Text (src/Chat.php:11844-11850). `handleInsightsCommand()` exists (Chat.php ~:12757)
  as a thin `runHostCommand(new InsightsHostCommand(), $inputText)` delegate, mirroring
  handleUsageCommand; `InsightsHostCommand` is in the BASE (8.c, ccf94cd63). Headless reach is automatic:
  `CommandMethods::runsIn()` derives `runsIn:"server"` from the spec's `hostCommand`, so no extra roster.
- READ_ONLY roster correct: `'insights'` appended to `READ_ONLY_COMMANDS` (declared Chat.php:10728, row
  at :10733 — the design's `:10266` cite is drift), consumed live by `isReadOnlySafeCommand()` (:10814).
  Semantics match the design ruling "the way `/export` is": reads the stores, writes only a shelf file
  (`AtomicFileWriter::write`, 0600, exports dir), never the locked session.
- Targeted gates (cwd=candy-code, env -u LINES -u COLUMNS, plain pipe, -c phpunit.xml):
  `--filter '(MenuBarLabelDerivation|BuiltInCommands|RegistryTranslation)'` → `OK … Tests: 29, Assertions: 1594, PHPUnit Deprecations: 2`
  `--filter '(SlashDispatch|CommandDocsDrift|ReadmeRosterDrift|PaletteSupersetDispatch)'` → `OK … Tests: 44, Assertions: 819, PHPUnit Deprecations: 2`
  `--filter '(DocFigureProseDrift|CommandDocsDrift|ReadmeRosterDrift)'` → `OK … Tests: 108, Assertions: 4221, PHPUnit Deprecations: 2`
  (the 2 deprecations + the bypass-permissions stderr banner are pre-existing at base; PtyRunToolTest excluded.)
- RegistryTranslationTest needs no row — verified live: it globs `builtin-commands/*.php` (:69) and walks
  `CommandRegistry::all()` (:146). Builder's in-step judgement was right.

## Findings
1. **MAJOR — weld-carry (merged figure + roster union), not a lane defect.** `docs/ARCHITECTURE.md`
   `dispatch arms for 63` is live-pinned by DocFigureProseDriftTest:713-718 against
   `count(CommandRegistry::slashCommands())`. The lane is correct at ITS base (62→63), but master 07e49e872
   already landed `builtin-commands/1405-output-style.php` and flipped the SAME numeral to 63. The merged
   tip therefore has **64** → the pick must land `64` or the merged tree reddens. Same block, second
   collision: README's `<!-- commands:roster:* -->` paragraph was re-WRAPPED by both sides (master added
   `/output-style`, lane added `/insights` on the same lines) → expect a cherry-pick conflict; the union
   must keep both spellings. COMMANDS.md hunks land at different offsets (:388 vs :398) — no conflict, but
   re-run the generator check after resolution.
2. **MINOR (step-local) — the read-only row is unpinned (vacuity).** Dropping `'insights'` from
   `READ_ONLY_COMMANDS` reddens nothing: the only tests that reflect that constant are
   HooksCommandTest::testHooksIsAReadOnlySafeCommand (positive) and
   GenerateCommandTest::testGenerateIsDeliberatelyNotAReadOnlyCommand (negative). Fix: mirror the house
   pattern in `tests/Commands/InsightsCommandTest.php` — add
   `testInsightsIsAReadOnlySafeCommand()` reading `(new \ReflectionClass(Chat::class))
   ->getReflectionConstant('READ_ONLY_COMMANDS')->getValue()` and `assertContains('insights', $allowlist, …)`
   (+0T only if it extends an existing method; a new method moves the live enumeration, so the weld's
   suite-figure re-pin carries it).
3. **NIT — scope.** `docs/ARCHITECTURE.md` is not in the block's `files.modify` list. It is
   guard-forced-by-construction (the added spec moves the pinned count) and disclosed in the REPORT, so it
   is accepted here — but it sits OUTSIDE the design's `serialized` set ([chat] [commands] [docs:README.md]
   [docs:COMMANDS.md]), which is exactly why 1. bites.
4. **NIT — prose.** The `READ_ONLY_COMMANDS` docblock narrates the judgement for several rows
   (`/diff`, `/fast`, `/generate`, `/workflow`, `/schedules`) but not `/insights`; its file-write is the
   one thing a reader would question. One clause beside the `/generate` sentence would make the allowlist
   self-documenting for this row too.

## Load-bearing check of the two touched rosters (mutation-reasoned, no tree edits)
- MenuBarLabelDerivationTest: `assertSame(self::DERIVED_LABELS, $derived)` over `CommandRegistry::all()` —
  bidirectional; deleting the `'insights' => 'Show insights'` row reddens it, and so does a stale row.
- PaletteSupersetDispatchTest::testThePaletteIsTheSupersetAndTheSurvivorsAreExactlyTheJudgedSet:
  `assertSame(array_keys(SLASH_ONLY_SURVIVORS), array_keys($survivors))` over rows with
  `paletteAction === null` — the new survivor row is required by the census (and the reason-string arm
  polices an empty reason). The spec-file's own no-paletteAction judgement comment matches the `/usage`
  sibling precedent.
- Deleting `2215-insights.php` entirely reddens five independent pins (README roster, COMMANDS.md,
  ARCHITECTURE count, DERIVED_LABELS, survivors) → the wiring cannot silently decay.

## Laws clean
E646 (no LLM call, no timer), no new proc_open/fork site, no roster row owed there; no weld-only files
committed (no suite-figure.json / durations TSV / dist); `declare(strict_types=1)` first, PSR-12, trailing
newline present, one type per file, `Lang::t()` for both user strings, comments carry WHY; `lang/` ships
`en.php` only for candy-code, so the all-locales-or-none law is not engaged; alphabetical key slot kept.
