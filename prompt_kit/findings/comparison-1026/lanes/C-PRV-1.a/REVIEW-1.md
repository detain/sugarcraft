# C-PRV-1.a REVIEW-1 (round 1, tip 30fddc88a)

[Persisted verbatim from reviewer reply — shell denied, static-only; weld must re-run gates.]

All verification axes complete. Review text (shell denied — static-only; gates need weld re-run):

**VERDICT: FINDINGS** (0 CRITICAL / 0 MAJOR / 1 MINOR / 2 NIT)

- Scope: 22 files map 1:1 to brief create/modify lists (3 new, 11 Providers src incl. both Invocations, Chat.php, lang/en.php, 5 tests, ADDING_A_PROVIDER.md). Chat hunks confined to handleEffortCommand (:12234-12292) — no unrelated lanes' markers; EffortCommandTest legitimately untouched (claude-sonnet-4-6 fixture stays yes). ThinkingCapabilityTest absent = NOT a gap (brief's files.create names only EffortGateTest).
- SETTINGS.md byte-unchanged claim VERIFIED sound: `tools/gen-settings-doc.php` never reads lang help strings; no "Thinking tier" text in SETTINGS.md; help renders via pre-existing ModelProviderSettings.php:152.
- Non-vacuity reasoned: neuter Bedrock thinking → BedrockProviderTest :685/:710/:765 red; drop temp leg → exact-array :691/:770 red; drop ceiling raise → :691/:708 red; remove Codex argv → CodexProviderTest:176 full-argv pin red; predicate→const true/false → roster pairs (:57-93, 26 pairs + shape sentences :128-154, byte-pinned) red both directions; 8-shaper roster + custom/echo complement pinned EffortBudgetTest :88-108.
- Wire shapes match neighbors (Bedrock top-level additionalModelRequestFields; `-c`+`k=v` argv pairs; `--effort`+level after flag block). Levels match probes (codex 7-table, claude 5-rungs via EffortBudget::levels). Lang help pair-table agrees with every predicate. §1.9/E646/§1.8 clean; staleness pair disclosed foreign-at-base; durations duty discharged via report.

Findings:
- [MINOR step-local] `BedrockProvider.php:622` — predicate keys on `anthropic.claude` head only, never consults `ThinkingCapability::supportsThinking`: `anthropic.claude-3-5-sonnet-*` answers YES and the wire ships `budget_tokens` the pre-3.7 generation rejects — the per-model lie this lane exists to kill, in one table asymmetric corner. Brief's roster example sanctions it, so not a deviation; fix = `&& ThinkingCapability::supportsThinking(family-form id)` + roster pair (bedrock+anthropic.claude-3-5 false). Else re-review loop keeps it.
- [NIT step-local] `Chat.php:12216` — docblock still says "The ShapesReasoningEffort roster decides"; post-C-PRV-1 the decision is `EffortGate::verdict` per model. One-line truth-flip.
- [NIT step-local] `ThinkingCapability.php:45,47` — NO_THINKING substring rules match future ids (`gemini-1` in `gemini-10`); fails closed toward refuse, harmless today.

Not verifiable in sandbox (shell denied): git author/trailers (lead pre-verified), clean tree, gate runs — weld must re-run Effort|BedrockProviderTest|Codex|ClaudeCode|OpenAIProviderTest|SettingsSchemaDocDrift + five-guard in the worktree.
