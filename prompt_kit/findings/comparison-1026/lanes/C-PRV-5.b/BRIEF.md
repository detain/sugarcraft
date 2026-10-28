### C-PRV-5.b — No thinking budget on the title and auxiliary backends
- item: C-PRV-5 (sources: CTX-1-P1)
- kind: fix · MINOR · effort S
- approach:
  - Root cause: `Bootstrap::toollessBackend()` (`Bootstrap.php:9484-9516`) builds from the selected provider's block, `thinkingBudget` included, and the Anthropic budget survives `withModel()` (`AnthropicProvider.php:395-409`).
  - So session titles, prompt suggestions, `/btw`, autoReview and commit subjects all pay for thinking tokens and latency.
  - Fix: `toollessBackend()` takes a `keepThinking` flag. It unsets `thinkingBudget` from the config copy unless the flag is set, and only `summaryBackend()` sets it (summaries keep the configured reasoning).
- files.create: `candy-code/tests/Cli/ToollessBackendThinkingTest.php`
- files.modify: `candy-code/src/Cli/Bootstrap.php` (`toollessBackend()`, `titleBackend()`, `summaryBackend()`)
- tests: create ToollessBackendThinkingTest: the title backend's request has no `thinking` key; the summary backend's does.
- docs/guards: `candy-code/docs/SETTINGS.md` thinkingBudget prose ("not sent on title and auxiliary calls")
- serialized: [bootstrap:toollessBackend, titleBackend, summaryBackend] [docs:SETTINGS.md]
- deps: none
- phase: normal
- ruling: none
- risks: none beyond the Bootstrap slot.

