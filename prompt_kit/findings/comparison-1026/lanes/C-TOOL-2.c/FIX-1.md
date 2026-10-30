# C-TOOL-2.c FIX-1 (round 1) — VERDICT: MINOR CURED, mutation-proven (3/3), all 4 reviewer gates reproduced, guards green

Lane worktree /home/sites/cc-cmp-C-TOOL-2.c, branch cc/C-TOOL-2.c, pre-fix tip 653a2349cc9f088b92ae8da5dbdacafd4b83c570 (cat-file'd; master 8defce213, base 1525b1832).

## Finding adjudication — the two cited sites need DIFFERENT arms (judgment as instructed)
MINOR (empty-`input` echo `{} → []` slip) ACCEPTED, cured at root cause. Investigation finding: the cited
defect is really THREE sites, and the two arms differ by where the block is built:
- `AnthropicMessagesWire.php` stream `server_tool_use` stop (was :1748) CONSTRUCTS the block — cured with the
  house one-expression itself: `$serverInput = is_array($decoded) ? $decoded : [];` then
  `'input' => $serverInput === [] ? new \stdClass() : $serverInput` — the :1094/:1862 idiom verbatim, no
  third shape. Side effect (disclosed): an UNDECODABLE input now echoes `{}` instead of the comment-advertised
  `[]` — the old sentinel was itself the invalid shape (guaranteed not-an-object 400), so `{}` is strictly
  better; the pre-existing comment sentence was truth-flipped in the same hunk.
- Batch pause echo (was :1216) re-sends `contentBlocks()` RAW wire-decoded blocks (no `input: {}` ever
  survives assoc decoding as a map) — construction-site cure impossible; cured with the shared tiny private
  helper `normalizedEchoBlocks()` (walks `tool_use`/`server_tool_use`, empties → `new \stdClass()`;
  idempotent — already-normalized stdClass and non-empty inputs untouched). Placed next to `contentBlocks()`.
- NEW third site found during the cure, same root cause, disclosed: the STREAM loop re-reads `$blocks =
  $leg['blocks']` of a resumed leg (was :2111) — those raw decoded blocks rode the next echo with the same
  `"input":[]` slip whenever a continuation paused a second time. Walked at the assignment. The :2085 echo
  line itself stays RAW by design: first-echo blocks are normalized at construction (:1748 arm), later-legs
  at the reassignment — so all three arms stay independently pinnable (a :2085 wrap would mask :1748).
Non-empty-input behavior byte-unchanged everywhere (substr-count pins + all 8 pre-existing methods green).

## Pins (AnthropicServerWebSearchTest, 2 NEW methods in the EXISTING lane file — NO new test file,
durations carry unchanged: lane still owes exactly the one row for this file, now 10T):
- `testABatchPausedEchoResendsZeroArgumentToolInputsAsObjects` — batch: json of the echoed turn has exactly
  2 `"input":{}` and zero `"input":[]`; the zero-arg call still reaches the loop as one Alpha ToolCall.
- `testAStreamedPausedEchoResendsZeroArgumentInputsAsObjects` — stream: first resume body 1 `"input":{}`,
  second resume body 2, zero `"input":[]` on both; final frame content 'second pausefinal' (no-separator law).
GlobDialect: no wildcard-shaped literals introduced (checked against the corpus guard, green).

## Mutation proofs (cp-restore + md5sum -c after every one, never git checkout)
- M1 revert :1748 arm → EXACTLY 1F = the streamed pin (batch green). Applied-verified before running.
- M2 revert :1216 walk → EXACTLY 1F = the batch pin (stream green).
- M3 revert :2111 walk → EXACTLY 1F = the streamed pin via its second-echo legs.
All restored: wire md5 05d1e438364f65fe845e8dddd4ec8396 re-verified OK.

## Gates (cwd candy-code, env -u LINES -u COLUMNS plain pipe, PtyRun expect-miss excluded — not matched)
- AnthropicServerWebSearchTest: 8T/52A reproduced EXACT pre-fix → 10T/66A post (pure +2T/+14A add).
- PromptPrefixByteStabilityTest 10T/102A, UsageTest|SearchBackendsTest 58T/293A,
  AnthropicProviderTest|VertexProviderTest 173T/485A — all byte-exact vs reviewer, unchanged (untouched paths).
- Combined named set 251T/946A green; family 'Anthropic|Usage|WebSearch|SearchBackends' 659T/2947A green.
- Guard families (DocFigureProse|GlobDialect|DuplicatedTestHelper|DuplicatedDocBlockLine|SymbolCitation|
  ChildWallClock): 143T/35351A green — zero new reds.
Reviewer PASSed surfaces untouched: PromptPrefix additions, max_uses exact array, webSearchRequests pair law,
no server_tool_use→ToolCall arm, leg-count-8 bound (E646: zero timers added), SearchBackends/ToolSettings.
Nits left to closeout per instruction.
