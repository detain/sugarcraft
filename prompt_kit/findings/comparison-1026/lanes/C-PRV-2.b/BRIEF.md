# BRIEF — C-PRV-2.b (wave 16)

## Responses reasoning replay and image tool outputs
- area: prv-ctx · kind: gap · effort M · effort:  · phase: normal
- item: C-PRV-2 (sources: GAP-MOD-02 (reasoning items), p1-miss: OpenAI Responses API (encrypted reasoning replay), candidates: "Responses also unlocks images in tool results")
- deps: C-PRV-2.a
- serialized: [docs:ADDING_A_PROVIDER.md] [hot:candy-code/src/Providers/OpenAIProvider.php] [hot:candy-code/src/Message.php] [hot:candy-code/src/Providers/Concerns/AnthropicMessagesWire.php]
- ruling: none

## Files to create
`candy-code/tests/Providers/OpenAiResponsesReasoningTest.php`

## Files to modify

- `candy-code/src/Providers/Concerns/OpenAiResponsesWire.php`
- `candy-code/src/Providers/OpenAIProvider.php` (vision for tool results on this arm)
- `candy-code/src/Message.php` (`withThinkingBlocks()` / row-hydrate filter at :794-797 and :1294, only if they reject the new block type)
- `candy-code/src/Providers/Concerns/AnthropicMessagesWire.php` (`anthropicBlocks()` ignores foreign block types, if it does not already)

## Tests
create OpenAiResponsesReasoningTest: encrypted items round-trip through save/load and replay; summary deltas reach the thinking channel; image tool outputs have the content-list shape; an Anthropic replay of a Responses-born row sends no foreign block.

## Docs / guards
`candy-code/docs/ADDING_A_PROVIDER.md` (thinkingBlocks ownership)

## Approach

- On the responses arm, request `include: ["reasoning.encrypted_content"]` and `reasoning.summary: "auto"`.
- Stream `response.reasoning_summary_text.delta` into the existing thinking channel.
- Store each reasoning item (`id`, `encrypted_content`, summary) as a `thinkingBlocks` entry of a distinct type on the assistant row. `Message::$thinkingBlocks` (`candy-code/src/Message.php:298`) is persisted and already used for Anthropic signatures.
- Replay those entries verbatim in later `input`, so reasoning carries across tool steps with `store:false`. Each wire replays only its own block types.
- Tool results that carry images go out as `function_call_output` with an `input_text` + `input_image` list, so a vision model sees them on this wire.

## Risks
C-TOOL-2's OpenAI image-in-tool-result leg (other area) rides this. That lane routes Read/MCP images into tool results; this step only serialises them on the Responses wire.
