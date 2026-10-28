# REVIEW-1 — C-EXT-5.a (PaletteCatalog core)

Worktree /home/sites/cc-cmp-C-EXT-5.a, branch cc/C-EXT-5.a, tip d37514fe828a812343053713a6ed1bcf761cc789 on base e4a705244. Reviewed 2026-10-28. READ-ONLY.

## Verdict

VERDICT: CLEAN

## Phantom-first

- `git cat-file -t` tip = commit; `rev-list --count master..HEAD` = 1; `status --porcelain` empty.
- `%ae` and `%ce` grepped separately: both detain@interserver.net; `%an` = Joe Huss; `%(trailers:only=true)` empty — zero Co-Authored/other trailers.

## Scope

`git diff --stat master...HEAD` = exactly the 4 declared files (+361, all new):
src/Palette/{PaletteCatalog,PaletteEntry,PaletteEntryKind}.php + tests/Palette/PaletteCatalogTest.php.
No modify-side files, no Chat.php/CommandRegistry/lang/docs/protocol/web touch — plan §2 region claims respected (palette dispatch regions belong to C-EXT-5.b, not this step). No weld-only files (suite-figure.json, durations.tsv, dist/) committed — re-pin + the 1 durations.tsv row for the new test file are weld duties, correctly left to the weld.

## Design conformance (BRIEF == design-ext.md §C-EXT-5.a)

- `PaletteCatalog::rows(list<CommandSpec>): list<PaletteEntry>` is pure static (private ctor, no state, no dispatch) — matches "pure function" approach verbatim.
- Action segment iterates `PaletteAction::all()` in declared order and copies `label()/category()/shortcut()` — these delegate to the same CommandSpec fields today's palette renders (`PaletteAction::label()` = `spec()->label()`, `category()` = `spec()->category`, `shortcut()` = `spec()->shortcut`), so the byte-identical-order/labels claim is structural, and `testActionRowsKeepOrderAndLabelsOfTheRegistry` pins it for `rows([])` input (count + per-index kind/label/category/shortcut + badge/commandName null + purity double-derivation).
- Prefill gate `!slashVisible || paletteAction !== null → skip` matches "one Prefill row per slash-visible spec lacking a paletteAction". Palette-only pseudo-commands (slashVisible=false, action set — e.g. CloseTab) still ride the action segment via paletteEntries' paletteAction-only filter — correct asymmetry, verified against live `CommandRegistry::paletteEntries()/slashCommands()`.
- Label composition: `'/'.$name` with `' '.argumentHint` appended only when the hint is non-null and non-empty — `testLabelOmitsSpaceWhenNoHint` pins both empty-shape polarities.
- Dedupe first-seen on label via `$seen` set, action rows registered before prefill walk — `testHiddenAndDuplicateRows` pins hidden-exclusion and first-wins (badge of the first spec survives).
- `tierBadge()` passed verbatim from `CommandSpec::tierBadge()` (`'['.tier.']'`, null for built-ins) — `McpPromptCommands::TIER='mcp'` exists, so MCP rows badge `[mcp]`; pinned by testMcpPromptCarriesMcpBadge via the real constant, not a literal.
- `PaletteEntryKind` is a standalone enum in its own file; one type per file in all three src files.

## Hand-traces

- Normal: registry rows → action segment unchanged from today's palette; survivor 'status' (slashCommands contains it, paletteAction null — asserted live in the test) yields Prefill `/status` (its hint rides whatever the spec declares), badge null, commandName 'status'.
- Edge: same-name user+project file rows dedupe to first-seen; Chat's `slashCommandRows()` is already name-keyed (`$byName`) so duplicates never reach the catalog in production — the in-catalog dedupe is defense-in-depth, correctly tested at the unit boundary.
- Edge: action rows always badge/commandName null (ctor defaults), prefill always '/'-prefixed label; since no action label starts with '/' (asserted live in testPrefillNeverCollidesWithActionLabels over the real registry), the two halves cannot collide, and that same test asserts the full-list labels are unique and the superset is strictly larger than the action list (kills the "prefill segment silently empty" vacuity).

## Non-vacuity of the mutation claims

My reviewer permission map denies `cp`/`php` invocations, so I could not re-apply the mutations in the worktree (and the task forbids repo edits as a baseline). Re-reasoned from code; both claims hold:
- Drop `|| $spec->paletteAction !== null`: action-bearing specs gain a `/name…` prefill; only `testSpecCarryingPaletteActionIsNotPrefilled` can see it (uniqueness test survives because prefill and action label spaces are disjoint; hidden test uses no actions) → exactly 1F. Claim SOUND.
- Drop `!$spec->slashVisible`: only the synthetic hidden spec in `testHiddenAndDuplicateRows` exercises it (registry input is pre-filtered by `slashCommands()`) → exactly 1F. Claim SOUND. Note this also shows the slashVisible gate is load-bearing only at the unit boundary (a caller feeding unfiltered `CommandRegistry::all()` — the documented C-EXT-5.b path shape) — correct placement.
- Red-before 8E: all 8 methods reference the three new classes; pre-commit the file fatals per test at class-not-found → 8E is the expected PHPUnit shape. Plausible, not re-run (would require deleting the just-reviewed files).

## Gates (reproduced, plain pipe, env -u LINES -u COLUMNS, cwd lane worktree candy-code)

- `--filter PaletteCatalogTest`: **OK … Tests: 8, Assertions: 401** (builder claim exact). The 2 PHPUnit deprecations are pre-existing/global — the untouched sibling `--filter PaletteAction` run shows the same count.
- Guard trio `GlobDialect|DuplicatedTestHelperDrift|TreeWideGuardRoster`: **OK … Tests: 44, Assertions: 4087** (builder claim exact).
- No PtyRunToolTest in any filter. New test literals checked against the GlobDialect corpus hazard: `'<env>'`, `'/cext5a-*'` carry no wildcards; run green confirms.

## In-step guard law / project laws

- No doc rows or census rosters needed moving: zero new PaletteAction cases, zero emitters/sprintfs, zero read-path or spawn sites, no lang keys (catalog emits no user strings — raw sanitised spec fields only), generated surfaces untouched.
- ReadmeSuiteFigureDrift staleness pair (pinned 25,537 vs live) is a FOREIGN standing red, not attributed here. DreamPassTest / AutomaticCompactionModelSummaryTest / ChatTest AsyncCmd-shape reds likewise not triggered by any filter run.
- E646: N/A (no LLM calls, no timers). Dormant code: nothing deleted; catalog intentionally unwired until C-EXT-5.b (explicitly the plan's wave-21 step).

## NITs (non-blocking, no action required)

- NIT: `PaletteEntry` is constructed via direct `new` rather than a `::new()` convenience factory; CommandSpec carries the same public-ctor precedent, so this is within house practice — noting only as a style datum for the b-lane consumer.
- NIT: PaletteCatalog docblock says MCP prompts are "badged through the `mcp` tier" — accurate, badge bytes come from tierBadge() verbatim; the source badge axis (sourceBadge) is intentionally not carried, consistent with the BRIEF naming only tierBadge.

## Weld reminders carried

- New file → 1 row in `scripts/parallel-tests-durations.tsv` at weld, plus suite-figure/README re-pin pair.
