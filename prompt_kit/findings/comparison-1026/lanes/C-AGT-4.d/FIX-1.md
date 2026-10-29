# C-AGT-4.d — FIX-1 (round 1, 2026-10-29)

Branch tip: 73a589cae16513a98a208549e767ad156a152346 (cat-file: commit) atop 1b9baf4a0.
One tracked change:

```
$ git show --stat 73a589cae
    C-AGT-4.d: review fixes (round 1) — agentRoster thread pins, mutation-proven
 candy-code/tests/Host/TurnControllerMcpSeamTest.php | 30 ++++++++++++++++++++++
 1 file changed, 30 insertions(+)
```

Authors: %ae and %ce grepped separately = 1 each (detain@interserver.net); zero
Co-Authored-By. Tree clean at tip.

## Per-finding map

**MAJOR-1 (agentRoster thread unpinned) — FIXED, test-side only, zero production change.**
Added `TurnControllerMcpSeamTest::testBothHostsFeedTheOneControllerRosterArmingSite`,
mirroring the sibling C-MCP-2.a `testBothHostsFeedTheOneControllerArmingSite` precedent
in the same file:
- `assertStringContainsString('agentRoster: $manager', …)` over the Chat.php
  `$this->turnController()->userTurnMessage(` window (anchor unique, grep -c = 1);
- `assertStringContainsString('agentRoster: $agentManager', …)` over the SessionHost.php
  `$turns->userTurnMessage(` window (anchor unique);
- `assertSame(1, substr_count($controller, '->withAgentRoster('))` sole-arming-site count.
Windows widened to 800 chars per reviewer. Measured distances at tip: roster line sits at
+286 (Chat) / +330 (SessionHost) from the anchor — inside 400 today, the 800 window buys
drift-margin so a new argument inserted before the roster line cannot silently push it out
(REVIEW-1's "past the 400-char slice" is directionally about margin; the literals were
still inside 400 at tip — recorded honestly).

**MINOR-1 (vacuous vue-tsc claim) — ERRATUM applied to REPORT.md:69** (evidence file,
untracked, main tree). Re-measured at this fix tip in the lane worktree:
`npm run typecheck` (= `vue-tsc --build`) → 21 error LINES / **19 unique** errors
(contextSources.ts:176 + :187 duplicate under the dual build); files: contextSources,
dormRoster.spec, statusBar.spec, contextPanel(.Edit).spec, reducer.spec,
contextTrustRouting.spec — **0 errors in lane files** (grep over Composer.vue/SessionView/
composerFilesImages = 0). Matches the batch3 base-residual family. Full log:
/tmp/opencode/C-AGT-4.d-fix1/typecheck-tip.txt.

## Mutation table (cp backups /tmp/opencode/C-AGT-4.d-fix1/, md5-verified restores)

| # | Mutation | Failing test | Restore |
|---|----------|--------------|---------|
| M1 | delete `agentRoster: $manager …` line in src/Chat.php | `TurnControllerMcpSeamTest::testBothHostsFeedTheOneControllerRosterArmingSite` (1F, Chat leg :152) | md5 7bba5157… restored OK |
| M2 | delete `agentRoster: $agentManager …` line in src/Host/SessionHost.php | same test (1F, host leg :155) | md5 0c14095a… restored OK |
| M3 | duplicate `->withAgentRoster($agentRoster)` in src/Host/TurnController.php | same test (1F, count leg :158: "2 is identical to 1") | md5 f8e44bf6… restored OK |

Each mutation reddened exactly the new pin — consistent with REVIEW-1's finding that the
rest of the suite is blind to these cuts. No repo stash used; git status clean post-restore.

## Gates

- Mandated filter `ContextMentions|Mentions|TurnController|SessionHost|DocFigure`
  (cwd=candy-code, vendor/bin/phpunit -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)
  after all restores: **309T / 5159A / 0F / 0E**, 2 pre-existing PHPUnit deprecations
  (house D-flag, unchanged from pre-fix). Lane carry: +1T/+3A (new pin method) — welder
  re-pin carries it in the +11T family figure; suite-figure.json/durations.tsv untouched.
- vitest not re-run (zero web files touched this round).
- `git diff 4dd8c20ce..HEAD -- src/Hooks src/Tools/BuiltIn/SendMessageTool.php src/Agents/SessionMailbox.php tests/Config/Support/suite-figure.json scripts/parallel-tests-durations.tsv candy-code-web/dist lang` — verified empty at fix tip (bans held).
