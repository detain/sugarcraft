C-PRV-1.a CLOSED · commit 30fddc88af6762eef0b4a0c1641d9878a1e20792 on cc/C-PRV-1.a (base 4f657c755, never pushed) · author/committer detain verified (separate %ae/%ce greps =1), zero Co-Authored.
22 files +1050/-43: EffortGate/ThinkingCapability new; shapesEffortFor on OpenAI/Anthropic/Vertex/Gemini/Sglang/Bedrock/Codex/ClaudeCode; Bedrock additionalModelRequestFields.thinking (+temp drop, +ceiling raise) in complete()/completeStream()/inferenceConfig; Codex -c model_reasoning_effort via execArgs (levels probed from installed codex-cli 0.154.0 dist: minimal low medium high xhigh max ultra); claude --effort (probe 2.1.306); Chat.php handleEffortCommand minimal hunk → EffortGate::verdict/shape; lang settings.reasoningEffort.help pair-naming rewrite; ADDING_A_PROVIDER.md shaper-contract paragraph; SETTINGS.md regen printed 'up to date' (help strings are not a generated surface — byte-unchanged).
Gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS): --filter BedrockProviderTest 67T/101A OK · Codex|ClaudeCode 221T/746A OK · OpenAIProviderTest 48T/107A OK · Effort (incl Chat EffortCommandTest, new EffortGateTest 4T/44A, EffortBudgetTest 7T/16A) 122T/395A OK · SettingsSchemaDocDrift 19T/179A OK · five-guard 133T/15328A F2 = ReadmeSuiteFigureDrift staleness pair ONLY (DocFigure/Glob/ChildWallClock/DupTestHelper green).
NEW test file for durations TSV: candy-code/tests/Providers/EffortGateTest.php.
Staleness pair split (stash-probed at pristine base): base already 25,537-pinned vs 25,564-live (+27 FOREIGN drift from post-pin welds — not mine, not fixed); my tree +19 (new tests/dataset rows). Gate re-pins the pair.
Deviations: ThinkingCapability has no dedicated test file (design lists only EffortGateTest as new) — its family/ceiling logic is pinned through the EffortGateTest verdict roster; 2 PHPUnit dataset deprecations under every filter are foreign (tests/Config/AccessorDeprecationTest non-public providers, present at base).
Bedrock pins are fixture-pinned only (no AWS creds) — disclosed in docblock per brief risks line.

## ROUND 2 (review fixes, off REVIEW-1 tip 30fddc88a)
Taken: [MINOR] BedrockProvider.shapesEffortFor now gates the Claude head through
ThinkingCapability::supportsThinking(self::family($model)) — family-form id, matching the
direct/Vertex arms; anthropic.claude-3-5-* now answers NO and ships no budget_tokens.
Roster pair added to EffortGateTest: bedrock + anthropic.claude-3-5-sonnet-20241022-v2:0 =>
false, bedrock + anthropic.claude-3-7-sonnet-20250219-v1:0 => true (4-x true legs kept).
Mutation: conjunct neutered -> exactly 'bedrock + claude-3-5-sonnet verdict' red (1F/39A),
restore md5-verified green. [NIT] Chat.php:12216 docblock truth-flip to 'EffortGate::verdict
decides, per model' (one line, handleEffortCommand region only).
LEFT: [NIT] ThinkingCapability NO_THINKING substring rules (e.g. gemini-1 inside gemini-10) —
ACCEPTED as-is: the rule fails closed toward refuse and is harmless for today's id space;
widening it to anchored generations is ThinkingCapability's own table-evolution duty, not
this step's.
Gates round 2: Effort|BedrockProvider 189T/504A OK · Codex|ClaudeCode 221T/746A OK ·
SettingsSchemaDocDrift 19T/179A OK · five-guard 133T/15328A F2 = staleness pair only (+0T
from this fix — roster entries are assertions inside existing methods; the pair's carry rides
the weld re-pin).
