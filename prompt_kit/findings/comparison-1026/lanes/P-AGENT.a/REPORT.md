# P-AGENT.a — LANDED (branch cc/P-AGENT.a @ /home/sites/cc-cmp-P-AGENT.a, base 8d142a648, never pushed)

Commits (4, all Joe Huss <detain@interserver.net> %ae+%ce verified separately, zero trailers; 16 files +610/−22):
- 9900a17a5 session+colour carriers: AgentLiveState gains `parentSessionId`/`color` (sticky once answered); AgentLiveRegistry gains `forSession(?string)` (null = whole view — the serve law) + `withColorResolver()` build-time seed; Bootstrap workspace() seeds `static fn (string $name): ?string => $agentManager->get($name)?->color` (single-line hunk in serialized region; backgroundShells untouched).
- e3ca80eac paints: AgentActivityLine::accent() (AgentColor::resolve ?? status colour); strip item, activity glyph, header name/glyph and dashboard rows tint through it; AgentStrip::itemsFor() beside byte-unchanged items(); App/serve readers keep items()/all() (renderer switch is P-TABS.g).
- 5cbde5bad INERT drop + docs + derived pins in-step: FrontmatterKeyAudit keeps only 'memory'; AGENTS_AUTHORING table row goes Live; AGENTS.md strip sentence added.
- ba9dd8539 pins: NEW tests/Agents/Live/AgentLiveSessionScopeTest.php (6T) + tests/Tui/AgentColorPaintTest.php (7T, incl. byte-identity of the plain path and visible-text parity of tinted rows); AgentLiveRegistryTest +1T, AgentStripTest +1T.

Gates (cwd candy-code in worktree, plain pipe): targeted 'AgentLive|AgentStrip|AgentColor|FrontmatterKeyAudit|InertFrontmatter' → 99T/1118A OK. Five-guard → 133T/15661A, ONLY the allowed ReadmeSuiteFigureDrift staleness pair (live enum +15 methods; closeout re-pin). 2 PHPUnit Deprecations environmental, identical at base.

NEW files for durations.tsv (gate duty, not touched here): tests/Agents/Live/AgentLiveSessionScopeTest.php, tests/Tui/AgentColorPaintTest.php.

Disclosures:
(a) tests/Cli/BootstrapLaunchNoticeRoutingTest.php was not on the brief's list; the INERT drop forces its aggregated notice row, so the inert fixture key flips color→memory in the same commit (§1.8 in-step law).
(b) Dashboard tint: row() looks up AgentLiveRegistry by the entry key (run ids); session-entry keys never match a live run id → miss = byte-identical unaccented path. tintedAgentLine mirrors AgentStatusBar::renderAgentLine format (duplication seam disclosed in docblock; fold owed if a second reader needs accenting).
(c) AgentColor::resolve returns ?\Core\Util\Color post cure-contrast era — every paint site consumes the Color directly (no re-wrap); token words bypass the contrast floor by AgentColor's own law.
(d) DuplicatedTestHelperDrift cure landed via amend: the paint test's SGR-strip helper is named plainCells (unique).

## Round 2 (review fixes, commit 5d78ddb7b)

- MAJOR fixed: production seed unpinned → NEW pin `AgentLiveSessionScopeTest::testTheProductionLaunchSeedsTheRegistryWithAColorResolver` — `Bootstrap::workspace($tmp/repo)->service(AgentLiveRegistry::class)` with HOME+$_SERVER sandboxed both directions (CompactionJournalTest:215 reachability shape), reflects the private `colorResolver` and asserts it is a Closure. Mutation-proved: deleting the `->withColorResolver(...)` line from Bootstrap::workspace() reddens exactly this pin ("Failed asserting that null is an instance of class Closure" at :149); cp-restore md5-verified before re-greens.
- MINOR fixed: AgentLiveRegistryTest — frame()'s `@param` docblock (:276-279) had been orphaned above the new fold test (back-to-back docblocks); moved down to sit directly above `frame()`. The P-AGENT.a docblock above the test is its honest owner and stays.
- NIT fixed: REPORT:9 "+14 methods" → "+15" (round-1 hand count: SessionScope 6 + ColorPaint 7 + RegistryTest 1 + StripTest 1 = 15; this round's seed pin is a +16th, and the closeout re-pin takes the live enumeration figure regardless). Second NIT (withColorResolver mutating with*) accepted as-is, no change per brief.
- Gates after fixes (plain pipe, env -u LINES -u COLUMNS): 'AgentLive|AgentColor|AgentStrip|StatusBar|Workspace' 268T/12422A green (= review's 267T/12420A + 1T/2A for the new pin); ShellContrast 14T/2408A green; five-guard 133T/15661A — ONLY the allowed staleness pair (live 26,037 vs pinned 25,537, +1 from this round; closeout re-pin duty unchanged). src/ untouched. Author law %ae/%ce both detain@interserver.net, zero trailers.
