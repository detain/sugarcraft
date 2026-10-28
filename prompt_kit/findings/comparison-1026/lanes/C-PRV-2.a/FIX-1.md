# C-PRV-2.a — Round 1 fixes (fixer, 2026-10-28)

New tip: **ac8ef966e3231b501957361edfe92d9c895319f0** (amend-in-place of bd2e0a2b1,
single commit on cc/C-PRV-2.a over master 5ed26cf92; message subject unchanged;
%ae/%ce = detain@interserver.net both; 0 Co-Authored/Signed-off trailers;
6 files +1647/−1 vs prior +1603/−1; porcelain clean; NEVER pushed).

## Per-finding cures

1. MAJOR — docs/SETTINGS.md (openai `api` paragraph): "raises the reply ceiling to
   `max_output_tokens` only when one is configured" REWORDED to the truth: "it always
   carries a `max_output_tokens` ceiling — the configured one, or the chat arm's 4096
   default, exactly as the chat arm always carries its `max_tokens` default". Prose-only.
   Old-sentence guard scan: grep of "raises the reply ceiling" across candy-code/tests →
   0 needles (only the docs line itself). Verified.
2. MINOR — OpenAiResponsesWire.php responsesEndpoint docblock: "gets a loud refusal
   rather than a silent guess" REPLACED with the silent-fallback truth matching the
   inline comment and code (`$base ??= self::SDK_DEFAULT_BASE`): "falls SILENTLY to
   that SDK default root — pinned by testNullBaseUrlFallsToTheSdkDefaultRoot". Prose-only.
   Guard scan: "loud refusal" test hits are all unrelated families (EffortBudget,
   SelfUpdate, …); none reference this docblock. Verified.
3. MINOR — docs/SETTINGS.md: "previously accepted and unused by this provider" REWORDED
   to the header-parity truth: "already sent on the chat wire by the SDK client that
   reads it; the responses wire carries it as its own `OpenAI-Organization` header".
   Guard scan: 0 needles. Verified.
4. MINOR (behavior) — stream arm `.incomplete` with no usage doc and no pending calls
   delivered truncated on NO frame. Fix: flag-only terminal yield after the usage leg:
   `if ($incomplete && $usageFrame === null && $pendingCalls === []) { yield new
   CompleteResponse(content: '', truncated: true); }` (Sglang payload-less-length-stop
   precedent). Pin: NEW testIncompleteStreamWithNoUsageAndNoPendingCallsStillDeliversTheFlag
   (assertCount(1) + assertTrue(truncated) + no-invented-payload legs: content '', toolCalls
   null, usage null).
   RED-BEFORE (pre-fix code, pin-only): 1F "actual size 0 matches expected size 1" :636.
   MUTATION M1 (post-fix, leg condition neutered via /tmp cp-harness): file alone →
   21T/93A/1F = exactly the new pin red, all other 20 green; restore cmp clean, RESTORE-CLEAN.
5. NIT — skipped per instruction (api legs stay in OpenAiResponsesWireTest; coverage present).

## Blob-verify (git show ac8ef966e grep -c, each expected 1; old sentences expected 0)

- "always carries a `max_output_tokens` ceiling" → 1
- "falls SILENTLY to that SDK default root" → 1
- "already sent on the chat wire by the SDK client" → 1
- "testIncompleteStreamWithNoUsageAndNoPendingCallsStillDeliversTheFlag" → 1
- "$incomplete && $usageFrame === null && $pendingCalls === []" → 1
- "raises the reply ceiling|previously accepted and unused|gets a loud refusal" in diff → 0
- working-tree residue scan: SETTINGS.md:532 "reply ceiling that thinking" is a
  PRE-EXISTING unrelated line (0 occurrences in the lane's 5ed26cf92..tip diff).

## Gates (cwd /home/sites/cc-cmp-C-PRV-2.a/candy-code, plain pipe, env -u LINES -u COLUMNS)

- `--filter 'OpenAIProvider|ProviderFactory|OpenAiResponsesWire'` → **259T/709A OK**
  (exit 0; delta vs review-reproduced base 258T/704A = exactly +1T/+5A; "issues" line =
  the 2 pre-existing PHPUnit deprecations, present at base).
- New file alone → **21T/97A OK** (+1T/+5A vs 20T/92A).
- `--filter 'DocFigureProseDrift'` after docs edits → 91T/3972A **1F** =
  testAgentsAuthoringToolClaimsSurviveTheBuiltInDirectory "thirty-seven word map" —
  the STANDING FOREIGN base red named in the brief (reviewer base-proven at 5ed26cf92).
  No NEW DocFigure red: the reworded sentences are unpinned (arm scans for max_output_tokens/
  OpenAI-Organization/SETTINGS provider-paragraph needles found only unrelated arms).
- php -l clean on trait + test file.
- No roster/guard flips needed: zero changes to suite-figure.json, durations.tsv,
  lang files, protocol trio, OpenAIProvider.php, ProviderFactory.php, ADDING_A_PROVIDER.md
  (the latter verified free of the false claims — its "ceiling" hits are the unrelated
  maxOutputTokens layered-key prose).

## Weld notes unchanged
Durations row for OpenAiResponsesWireTest (now 21T) + README/suite-figure re-pin owed to weld.
