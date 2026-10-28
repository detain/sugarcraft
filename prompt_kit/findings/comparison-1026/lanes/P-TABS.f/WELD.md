# P-TABS.f WELD — 2026-10-28

Pick: c991c6d10 → master 0fb10ffbf (one commit). Durations row: scripts commit 8aaa3dcc2.
Prior master tip: cbe74fb5a.

## Conflict resolution (one, cosmetic)

`candy-code/tests/Config/DocFigureProseDriftTest.php` — thirty-seven word-map
hunk: master already carries the `$wordNumbers['thirty-seven'] = 37;` line with
the C-AGT-5.a-lag comment (landed via C-TOOL-3.d weld 872d6da44, keep-once).
The lane re-added the same line with its own comment. Resolution: kept the
master-side comment block, line present exactly once; the lane's semantic hunk
(palette-only roster seven→eight with `close-tab`) applied clean outside the
conflict. Both intents preserved; no behavior difference. Chat.php, App.php,
en.php, COMMANDS.md auto-merged.

## No regeneration riders

`git show --stat 0fb10ffbf`: zero `src/Protocol/**` files, zero `candy-code-web`
files → no protocol trio, no web build, no dist. COMMANDS.md row arrived with
the lane (generator-produced in-step, CommandDocsDriftTest verifies).

## Gates (merged tree, plain pipe, env -u LINES -u COLUMNS, cwd candy-code)

| Filter | Result |
|---|---|
| DeckHostedSessionRoutesTest | OK Tests: 14, Assertions: 77 (expected 14T/77A era EXACT) |
| AppModelTest\|TabScopedRouting\|BackgroundTabStreaming\|TabCloseAndQuit | OK Tests: 82, Assertions: 617 |
| CommandDocs\|LangParity | OK Tests: 13, Assertions: 2915 |
| DocFigureProseDrift\|PaletteSuperset | OK Tests: 97, Assertions: 4209 |
| GlobDialect\|StderrEmitterCensus | OK Tests: 109, Assertions: 21650 |

## Findings (report-only, NOT attributed to P-TABS.f)

1. **STANDING RED — ShellMenuTest ×2, foreign, introduced by C-TOOL-3.d.**
   `tests/Tui/Menu/ShellMenuTest::testEveryRegistryRowIsPlacedExactlyOnceAndNoPlacementIsABluff`
   and `::testTheShippedTreeHasNoMoreSubmenuBecauseNothingIsUnplaced` fail at
   current master: the `shells` registry row (builtin-commands/2905-shells.php,
   pick 734f97dda) was never placed in `ShellMenu::PLACEMENT`. Proven
   base-inherited: 734f97dda is NOT an ancestor of the lane base 2cd5294e9
   (landed after the lane cut); ShellMenu.php at cbe74fb5a has zero `shells`
   hits; the C-TOOL-3.d weld gates ran `MenuBar|PaletteSuperset` and missed
   `ShellMenu`. Our pick places `close-tab` correctly (only the shells shift
   appears in the diff). Owes: a fix for the C-TOOL-3.d owner (add
   'shells' to PLACEMENT, likely under the session branch/sweep submenu), and
   ShellMenu should join the weld-gate filter list for registry-row-adding
   lanes.
2. **Standing red (known):** `AutomaticCompactionModelSummaryTest::testClearingTheTranscriptUnarmsTheBreaker`
   — base-reproduced foreign (r1 proof, Chat-region band owner). Recorded in
   run-state standing_reds; not fixed here.
3. **NIT accepted:** `withTabHosted()` is identity-preserving when the flag is
   unchanged — deliberate, documented, test-pinned.

## Closeout carry

+14T / +77A suite-figure drift from DeckHostedSessionRoutesTest (1 new file;
durations row already landed at 8aaa3dcc2, tsv 1514→1515, set-equality +1).
run-state: welded_count 142→143, P-TABS.f inflight→welded; 5 inflight lanes
(C-AGT-5.e, C-AGT-5.g, C-AGT-6.d, C-CTX-4.c, C-WEB-4.a) preserved.
