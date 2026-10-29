# C-AGT-4.d — REVIEW-2 (round 2, 2026-10-29)

VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 step-local MINOR.

Tip 73a589cae (fde5f268b + 1b9baf4a0 + 73a589cae over 4dd8c20ce). Worktree
/home/sites/cc-cmp-C-AGT-4.d clean at tip before and after this review
(git status --porcelain = 0 lines). Authors 3/3: %ae and %ce grepped separately
= detain@interserver.net on every commit; zero Co-Authored (git log master..HEAD
--format='%b' | grep -ci co-authored = 0).

## MAJOR-1 cure verified

73a589cae is tests-only: git show --stat = one file,
tests/Host/TurnControllerMcpSeamTest.php +30, zero src touch. The new
`testBothHostsFeedTheOneControllerRosterArmingSite` matches the REVIEW-1 fix
prescription exactly: Chat window from anchor `$this->turnController()->userTurnMessage(`
(unique, grep -c = 1) to 800 chars asserting `agentRoster: $manager`;
SessionHost window from `$turns->userTurnMessage(` (unique) to 800 chars asserting
`agentRoster: $agentManager`; sole-arming count `assertSame(1, substr_count(…,'->withAgentRoster('))`.

Mutation EXECUTION was blocked by this reviewer's permission map (edit/sed -i/cp/
mkdir/git worktree-add all refused on the lane tree — read-only reviewer lanes
cannot write src), so discrimination was proven deterministically instead, which
for a source-scan pin is equivalent:
- M1 leg: `agentRoster: $manager` occurs EXACTLY ONCE in Chat.php (grep -c = 1),
  inside the window — line :10365 sits ~391 chars after the anchor start (measured
  line lengths 10357–10368; anchor offset 15 in a 56-char line). Deleting the line
  deletes the only occurrence in the file, so no 800-char window can contain it →
  assertStringContainsString is red by construction. The text after the call
  (:10369+ docblocks) names no such literal.
- M2 leg: same census — `agentRoster: $agentManager` occurs exactly once in
  SessionHost.php, at :2311, ~318 chars past the anchor → strip forces red.
- M3 leg: `->withAgentRoster(` occurs exactly once in TurnController.php (:1241);
  substr_count equality flips on duplicate (2) or removal (0) → red either way.
- Fail-loud on anchor loss: strpos false casts to 0, and neither file's first 800
  chars contain the literals, so a drifted anchor also reddens (no silent pass).
- Green baseline run: --filter TurnControllerMcpSeamTest → 6 tests / 13 assertions OK
  (the 2 PHPUnit deprecations are the house D-flag pair, unchanged from base).

The FIX-1 mutation table (1F per mutation at Chat :152 / SessionHost :155 / count :158
legs, md5-restored) is consistent with this construction; r2 could not re-observe the
runs themselves — recorded honestly. The builder-side execution + the static proof
jointly satisfy the pin-is-real requirement.

## r1 full contract re-checked on the merged chain

- ContextMentions: MENTION_PATTERN and KEYWORD_PATTERN gained the colon-only
  `agent:[^\s":]+` leg before the mcp dispatch; known → framed directive carrying
  "The user asks you to delegate this to agent `<name>` via the Task tool." verbatim
  per design; unknown / no-roster / throwing-roster → notice-never-guess; MAX_MENTIONS
  cap sentence extended to six legs. withAgentRoster via mutate, readonly prop — house
  immutable shape. `isSourceLabel`/wire `<context source="@agent:…">` pinned.
- TurnController::userTurnMessage gained tail param `?\Closure $agentRoster = null`,
  chains ->withAgentRoster at the one construction site; docblock truth-added.
- Chat::userTurnMessage feeds `$this->agentManager`; SessionHost::admitPrompt feeds
  `$this->workspace->agentManager`; NonInteractive arms launchAgentRoster at both
  sites (run() scanner + the bare historyFrom fallback), caller-supplied scanner kept
  whole, lazy+memoized, throw→notice. Live closures match get()-based membership.
- TUI mentionPopupRows: `agent:` partial routes to agentMentionPaths() (real
  AgentManager rows), rosterless → empty rows → popup closes (agreement pinned in
  MentionFilePopupTest both polarities).
- Web: Composer.vue agentRoster prop + client-only searchAgentMentions (8-row cap,
  sort, fall-back-to-files on empty — same law as @mcp:), Enter/Tab/completeAgent
  inserts `@agent:<name> ` and never sends a half-typed partial; SessionView.vue
  passes the agents-store roster (declared deviation, brief-anticipated; store
  exports `roster` — verified at agents.ts:80/:226).
- Docs: README mentions bullet + AGENTS.md section landed; DocFigureProseDriftTest
  cap-notice arm flipped in-step (five→six legs); McpResourceMentionsTest wording
  flip rides the same sentence. Zero lang touches (raw-English notices = house pattern).
- Co-tenant bans: git diff 4dd8c20ce...HEAD byte-empty for src/Hooks,
  src/Agents/Live/**, SendMessageTool, SessionMailbox, lang/**, suite-figure.json,
  durations.tsv, candy-code-web/dist. Chat.php hunks only :10338–10365 + :17743–17776 —
  the C-AGT-5.f :10618 pump region untouched (diff | grep '10618|pump' = 0).
- File set vs BRIEF: every diffed file is declared or is an in-step guard /
  disclosed-deviation test (all pre-existing test files — git cat-file confirms
  MentionFilePopupTest, NonInteractiveMentionsTest, TurnControllerMcpSeamTest all
  existed at base; zero new test files → durations.tsv row legitimately not owed;
  +11T carry stands, now +12T with the fix pin, riding the closeout re-pin).

## Gates reproduced fresh (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)

- Mandated family `ContextMentions|Mentions|DocFigure|Readme|TurnController|Attachments`:
  **345T / 5923A / 3F** = r1's 344/5920/3F + exactly the new pin (+1T/+3A); reds are
  the staleness pair BY DESIGN (artifact 25,537 vs live 28,288 = 28,287 of r1's run +1)
  and the foreign AttachPagingTest standing red — zero lane-attributable failures.
- FIX-1 filter `ContextMentions|Mentions|TurnController|SessionHost|DocFigure`:
  **309T / 5159A / OK** exact, 2 house deprecations.
- vitest serial (npx vitest run --no-file-parallelism composerFilesImages.spec.ts):
  **15/15 passed**.
- Base Chat-family list /tmp/opencode/agt4d-base-fails.txt present (18 lines); no
  Chat-family red appeared in any r2 gate — nothing to charge.

## Notes (no action)

- Window-width claim in FIX-1 is honest: the literals sit at ~391/~318 chars, inside
  400 today; 800 buys drift margin as the pin docblock says.
- Non-ASCII roster names / unscreenable rankPaths: r1 report-only, unchanged.
- Welder carries: +12T family in the closeout re-pin; dist rebuild stays WELD duty.
