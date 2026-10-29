# C-CTX-3.e — LANDED 877062eab (branch cc/C-CTX-3.e, base b9a3b0fff, not pushed)

Memory export verb + prompt-visible import scope (GAP-MEM-14). 7 files, +621/−52.

- `src/Memory/ForeignMemoryImporter.php`: optional `?MemoryWriter` router ctor param;
  both import methods take `MemoryScope $scope = Local`, writes route via private
  `note()` (writer→save(), else store->add()). Default path byte-identical.
- `src/Host/Commands/MemoryCommand.php`: `export` arm (read-only, not in $mutates) —
  one markdown bundle, per-note writeEntry-shaped frontmatter, store-grouped sections,
  0600 AtomicFileWriter, PathJail + home-shelf rules mirroring ExportCommand, default
  `exports/memory-<session>-<stamp>.md`; `import` gains `--scope user|project|agent`
  either position, unknown word → usage, promoted scopes route through writer
  (indexed/recalled/folded); docblock truth-flips; sentinel literal byte-kept.
- `lang/en.php`: +host.memory.export.* (7), help.export, import.done_promoted,
  usage.export; usage.import names flag. `docs/MEMORY.md`: fence lines, BF scope
  sentence reworded, `### Exporting the store` section; all pinned sentences kept.
- Guard in-step: DocFigure BF arm re-derived (note() call sites + defaulted sigs).
- Tests: MemoryImportCommandTest +3 (section 8), usage needle flipped; NEW
  **candy-code/tests/Host/Commands/MemoryExportCommandTest.php** (8T/47A) — welder:
  needs durations.tsv row; lane adds +11T total.

Gates (cwd=candy-code, targeted): Export 8T/47A OK; Import 14T/90A OK; DocFigure+
MemDocDrift 100T/4147A OK; importer/memory units 199T/730A OK; census fams +
five-guard 302T/42054A OK; Host|SlashDispatch 201T only-reds = suite-figure
staleness pair + ContainedPathInventory x2 — BOTH base-inherited (reproduce on main
tree 8754885cb, foreign files AgentMemory/SkillLoader/PathJail), not lane-fixable.
Mutations 4/4 discriminating, md5-clean: 0600→0666 = 3 reds; jail→raw = 2 reds;
scope-guard neuter = 1 red; BF doc comma-drop = 1 red.

## FIX ROUND 1 (follow-up commit on 877062eab)

- MAJOR-1 fixed: `MemoryCommand.php` export arm now uses a ternary of the two
  FULL literal keys (`$which === 'home' ? 'host.memory.export.section.home' :
  '...repo'`) — precedent `host.memory.search.heading.*` / `import.none_refused`
  — so the concatenated partial key disappears from the placeholder census.
  `host.memory.usage.export` DELETED from `lang/en.php`: zero src callers, and
  export takes only an optional path (no missing-argument door a one-line usage
  wire could stand in), so delete per review ruling. `--filter
  ChatHostLangCatalogue` now leaves exactly 1F: the stranded FOREIGN six chat.*
  keys (startup.personal, attach.pdf_pages, effort.launch_unshaped,
  permission.not_persisted, permission.persisted, serviceTier.launch_unshaped);
  the placeholders arm and the English-rendering arm are GREEN and my three key
  names are gone. Pre-existence proven: those six sit in lang/en.php at base
  b9a3b0fff with zero Lang::t call sites in the scanned set (git grep) — the
  REVIEW-1 "base-inherited" certification holds independently.
- MINOR fixed: `tests/Commands/MemoryImportCommandTest.php` section 9 — new
  `seedClaudeMemory()` helper + TWO smuggling pins: (1) a claude fixture
  claiming `scope: project` under the default caller scope must land in
  `agent`, file nothing in the repo store or home project tier, and carry the
  claim not even as a tag; (2) a fixture claiming `scope: agent` cannot DEMOTE
  an explicit `--scope user` promotion. Importer reads only `description` +
  `metadata.originSessionId` — both directions pinned against a future
  meta→scope wiring. Mutation proof: injecting
  `match($meta['scope'])…` at the `note()` call site of `importClaudeCode()`
  reddens EXACTLY these two tests; cp+md5 restore clean.
  Figures corrected (this REPORT's "+11T" is now stale): Import 14T/90A →
  16T/101A; lane adds +13T total (Export 8 + Import 5). Welder: durations.tsv
  row MemoryExportCommandTest + figure re-pin at +13T.
- NIT recorded, NOT redesigned: the existing share/export idiom offers no
  suffix-uniquifier to reuse (`/export` REFUSES on conflict via
  cmd.export.conflict; ShareCommand has no collision loop), so exportName's
  second-precision UTC stamp keeps same-second default overwrite (0600 rides
  the rename; user's own data). Closeout carry: killing it means a
  `while (file_exists())` suffix walk in exportName() — a behavior decision
  for the owner, not a bug-fix.
- Inherited-at-TIP reds disclosed (both reproduced on the UNMODIFIED tip
  877062eab with the fix files reverted, so NOT lane- or fix-caused):
  `MenuBarLabelDerivationTest::testEverySlashOnlyRowReadsAsThisTable` — the
  `/instructions` + `commit` registry rows landed by the batch-10 weld were
  never added to that fixture's expected table; and
  `WorkspaceHostTest::testAnotherRootRunsInItsOwnProcessAndATurnStreamsBackThroughTheGateway`
  — environmental gateway test, fails isolated.
- Gates this round (cwd=candy-code -c phpunit.xml, plain pipe, env -u LINES -u
  COLUMNS): MemoryImportCommandTest 16T/101A OK; MemoryExportCommandTest
  8T/47A OK; `MemoryExport|MemoryImport` 78T/273A OK; `MemoryCommand` band
  36T/138A OK; `DocFigureProseDrift|MemoryDocumentationDrift|
  FrontmatterCallSites` 108T/4160A OK; `GlobDialect|DuplicatedTestHelperDrift|
  TreeWideGuardRoster|ReadPathCensus|StderrEmitterCensus` 159T/27061A OK
  (byte-exact vs REVIEW-1's 159T/27061A); `Host|SlashDispatch` 903T/6724A
  with ONLY the two disclosed inherited/foreign reds.
