# C-PRV-2.a — Round-2 cure-verification REVIEW (reviewer: cc-cmp step reviewer, 2026-10-28)

VERDICT: CLEAN — 0 critical / 0 major / 0 minor (step-local). All four round-1 cures verified against CODE, not prose. Tip ac8ef966e, ONE commit on cc/C-PRV-2.a over 5ed26cf92, porcelain clean, %ae/%ce detain both, 0 trailers, diff = exactly the 6 declared files (+1647/−1), no weld-only/generated files.

## Cure-by-cure disk verification
1. MAJOR FIXED. SETTINGS.md:575-578 now reads "always carries a max_output_tokens ceiling — the configured one, or the chat arm's 4096 default, exactly as the chat arm always carries its max_tokens default". TRUE against trait:86 (`'max_output_tokens' => $request->maxTokens ?? 4096`, unconditional in responsesBody) and against the chat arm it cites (OpenAIProvider.php:547 `'max_tokens' => $request->maxTokens ?? 4096`). Old "only when one is configured" sentence: git grep HEAD = 0.
2. MINOR FIXED. Trait:660-663 docblock now says "falls SILENTLY to that SDK default root — pinned by testNullBaseUrlFallsToTheSdkDefaultRoot", matching code :676 `$base ??= self::SDK_DEFAULT_BASE` and the inline comment. Pin (test:199-213) is honest: builds a null-base provider, asserts the real request URI == 'https://api.openai.com/v1/responses' (SDK_DEFAULT_BASE constant :57 + '/responses').
3. MINOR FIXED. Base blob git show 5ed26cf92:ProviderFactory.php :876 already had ->withOrganization($config['organization'] ?? null) — the old "previously accepted and unused" was false, and the new SETTINGS.md sentence ("already sent on the chat wire by the SDK client that reads it; the responses wire carries it as its own OpenAI-Organization header") is true on both halves: trait:650-651 sets the header on the raw arm; factory threads organization: into the ctor.
4. MINOR FIXED. Trait:474 `if ($incomplete && $usageFrame === null && $pendingCalls === [])` flag-only terminal yield. Edge table hand-traced: usage-only + incomplete rides :457 (truncated:$incomplete); calls-pending rides :453; the flush branch never re-clears $pendingCalls, so :474 cannot double-deliver. Pin test:616-643 asserts the delivered CompleteResponse (assertCount(1), assertTrue(truncated), content '', toolCalls null, usage null) — not a call count. Red-before structurally sound: fixture {"id":"r5"} has no usage doc (usageFrame stays null via :407-413) and no calls, so pre-fix the generator yielded zero frames and assertCount(1) fails exactly as the fixer reported (:636).
5. NIT skipped per instruction — api-key legs live in OpenAiResponsesWireTest :717-765 (default/carry/refuse), accepted.

## Gates reproduced (cwd worktree candy-code, plain pipe, env -u LINES -u COLUMNS)
- --filter 'OpenAIProvider|ProviderFactory|OpenAiResponsesWire' → OK Tests: 259, Assertions: 709 (base 258/704 + exactly the new pin +1T/+5A; 2 PHPUnit deprecations pre-existing).
- OpenAiResponsesWireTest alone → OK Tests: 21, Assertions: 97.
- DocFigureProseDrift → Tests: 91, Assertions: 3972, Failures: 1 = testAgentsAuthoringToolClaimsSurviveTheBuiltInDirectory 'thirty-seven' word-map — arm reads docs/AGENTS_AUTHORING.md (NOT in the lane diff); standing foreign base red, no new red from the rewords (all three old sentences grep-clean at HEAD; unrelated 'loud refusal' hits are other families).

## Law re-sweep
E646: zero `'timeout'` in the trait; structural pin testTheWireAddsNoTotalRequestTimeout present (:725). store:false unconditional (:85); flat tools/normalizeToolSchema + ONE parseUsage pricing seam (:409 stream, :549 batch, both foldResponsesUsage→parseUsage) — round-1 verified, untouched by cure. withModel :351 get_object_vars spread carries api/organization/responsesClient. Factory refuses unknown api by name with get_debug_type. One type per file (1 declaration each in the two new files). Guard/roster/census files absent from diff; durations + suite-figure re-pin correctly OWED to weld.

Lane is weld-ready.

VERDICT: CLEAN
