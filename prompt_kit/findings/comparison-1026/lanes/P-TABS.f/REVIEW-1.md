# P-TABS.f REVIEW-1 — verdict: CLEAN (0 CRITICAL / 0 MAJOR / 1 MINOR / 1 NIT)

Hygiene: tip c991c6d10 exists, master..HEAD = 1 commit, porcelain 0, %ae/%ce = detain (separate greps), zero trailers; git show --stat == exactly the 10 declared files (+22/+7/+6/+27/+151/+4/+2/+314/+2/+10). merge-base 2cd5294e9 (builder's disclosed deviation from phantom brief base db1e63739 — accepted, real tip).

Semantics verified on disk:
- Standalone parity: all four route arms structurally reduce to the historic code when tabHosted=false — picker resume/fork return [switchToSession(...), null] tuples identical to base's inline calls; Agent-View keeps the no-cmd shape; palette New-session keeps its bespoke block behind the flag door. Lane's standalone pins assert the exact historic notices (1 appended row naming the action, picker close, id unchanged).
- Hosted stand-not-refuse is intent-only: presentSession returns [$this, Cmd::send(OpenTabMsg)] (history/id/turn untouched — pins assert zero appended rows + sess-a stays + inFlight survives); CloseTab routes to App::handleCloseTab, which owns the last-tab floor and busy two-press arm (App.php:5679-5700, pre-existing). No permission gate touched: gates relaxed are inFlight edits only; not_stored/no_sessions problems stand. Cmd::send is the established Chat→App channel (InitialPromptMsg/OpenSettingsMsg precedent).
- Close tab standalone: named notice chat.tabs.close_no_deck (row asserted), never silent; idle and mid-turn both.
- Palette-only row: slashVisible:false mirroring 0101-new-picker; COMMANDS.md lists it with blank S exactly like the palette-only siblings (generator output, prose seven→eight in-step); menu click path routes via App::runRegistryCommand → palette action (documented behavior for slashVisible:false rows), so the Session-menu placement is consistent with new-picker/session-pin; ShellMenuTest (no-More-submenu pin) green.
- Clone-carry: 'tabHosted' leg added to mutate()'s constructorProps map (Chat.php ~:9091) — the same single carry map P-TABS.e-era fields use; resume/fork/palette tests all pass through ≥2 mutate hops, so a dropped leg reddens them (verified by reading the drives).

Guards in-step: DocFigure palette-only roster flip re-derives against live $list order (registry file order 0101/0105/0210 matches expected list) — green; the thirty-seven word-map line is a TRUE inherited base-red cure (base docs/AGENTS_AUTHORING.md:463 says "thirty-seven", base BuiltIn dir counts 37, base map topped at thirty-six → assertArrayHasKey fails at base). PaletteSuperset flip is a reason-string, not a loosened assertion. CommandDocsDrift/StderrEmitterCensus(0 new emitters in diff)/GlobDialect/DupHelper/KeyBindingDrift all run green.

Reproduced gates (plain-pipe, cwd candy-code, -u LINES -u COLUMNS): DeckHostedSessionRoutesTest 14T/77A green; guard filter 153T green; CommandRegistry/docs/census filter 367T green; tests/App 468T green; tests/Chat 1224T/2F. New test file 13E-at-base claim is structural (ctor named-arg tabHosted absent at base ⇒ named-arg construction errors; the 1 idle-fork byte-pin is pure legacy). Mutations not executable (src edit denied for this reviewer); vacuity reasoned per pin — all assert concrete msg classes/ids/row counts, no vacuous shapes.

Hazards clean: zero timers (E646), no proc_open, one type per file, lang 3 new keys en-only (only en.php exists; §6.3-h 11-locale wave not yet landed at base — parity-last law not triggered), GlobDialect/DupHelper green, weld-only files untouched (durations row correctly left to welder, REPORT names it).

MINOR (out-of-scope, disclosure): tests/Chat/AutomaticCompactionModelSummaryTest::testClearingTheTranscriptUnarmsTheBreaker fails deterministically (isolated, 38T/1F) — reproduced with the BASE Chat.php swapped in (restored, porcelain 0 verified): inherited base red NOT in the builder's disclosed list. Welder/gate should track it.

NIT: withTabHosted() identity-preserving return deviates from the house "with* returns a new instance" letter; deliberate, documented, test-pinned (byte-stable factory-less object flow) — acceptable.

Inherited ReadmeJsonErrorContractDriftTest 4F re-proved foreign: all four name src/MCP/McpServerRequests.php, zero lane-file overlap (diff --name-only grep 0), test file byte-identical at base — disclosure honest.
