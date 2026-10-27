# C-PRV-1.a REVIEW-2 (round 2, tip 0a5ad3c99)

[Persisted verbatim from reviewer reply — shell denied; weld re-run of gates owed.]

VERDICT: CLEAN

- [OK] Bedrock fix genuine: `shapesEffortFor` (BedrockProvider.php:625-626) now ANDs `ThinkingCapability::supportsThinking(self::family($model))`. Shape verified: `supportsThinking` (:87-96) is substring-based (`str_contains 'claude'` + `/claude-(?:…|3-5)/`), so the vendor-kept `anthropic.claude-*` family form hits identically to raw ids passed by Anthropic/Vertex/Gemini arms (:391/:366/:223) — gating real, not vacuous. Hand-trace: `anthropic.claude-3-5-sonnet-20241022-v2:0` → NO; `claude-3-7...` → YES.
- [OK] EffortGateTest roster pair added (+4 lines, 3-5 false / 3-7 true); all pre-existing bedrock rows re-traced — none flips under the new conjunct, so the claimed mutation (conjunct-neuter → exactly the 3-5 leg red) is consistent.
- [OK] Chat.php: one-line docblock at :12216 only; full commit patch reviewed — no other Chat hunks; new wording code-true (`EffortGate::verdict` at EffortGate.php:37).
- [OK] REPORT.md round-2 section present in main tree (:9-25) incl. accepted-substring NIT with fail-closed rationale; fix commit header detain/detain, no trailers (parent lead-verified).
- [note] Shell denied this session: phpunit gates (189T/504A etc.) and a fresh cumulative 22-file diff not re-run here; fix adds zero files so cumulative shape is claim-consistent — weld re-run owed per standing note.
