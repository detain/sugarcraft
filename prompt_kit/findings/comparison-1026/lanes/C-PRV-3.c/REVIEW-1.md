# REVIEW-1 — C-PRV-3.c (ultrathink one-turn effort keyword) @ 01c230f8c

VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 2 MINOR (step-local) / 2 NIT.

## Gates re-derived (lane worktree, plain pipe, cwd=candy-code -c phpunit.xml)
- UltrathinkTurnTest: 17T/30A OK (2 PHPUnit deprecations are FOREIGN — AccessorDeprecationTest non-public providers, collection-time, every filter shows them).
- TurnRunner|EffortGate|EffortBudget|ThinkingCeiling: 70T/286A OK.
- DocFigure|TrustKey|EnvRoster|ConfigWriteProducer|StderrEmitterCensus|LangParity|RuntimeNotice: 334T/75,980A OK (superset of builder's 313T/75,921A window — green covers).
- GlobDialectDifferential: 13T/366A OK at tip's 559×555.
- ChatHostLangCatalogue: 1F with EXACTLY the six foreign orphans (startup.personal, attach.pdf_pages, effort.launch_unshaped, permission.not_persisted/persisted, serviceTier.launch_unshaped) — chat.effort.ultrathink_none is NOT among them (asked-for by src/Host TurnRunner, roster-included). Builder's foreign base-red claim TRUE.

## §1.8 PathGlob flip verified
- base 8754885cb docblock read via git show: 309,132 (558×554). Tip: 310,245 (559×555); arithmetic exact.
- Lane files add ZERO glob-shaped literals: EffortKeyword's '/\r\n|\r|\n/' and '/\b' fragments carry no *?[ wildcards; en.php value has none; test literals none. So live derivation = 559×555 already at base → guard was red-at-base; the flip is the named cure. (In-tree swap probe denied by lane permissions; conclusion proven by corpus-rule inspection + tip green.)

## Semantics audit
- One-turn: lift acts on the thunk-captured clone before `return static function () use ($backend…)` — reflection pin real. Caller backend untouched (assertNull); Chat/SessionHost re-mint backendForTurn from $this->backend each dispatch, so next typed row starts at configured tier (test 2 discriminative: a persist-to-caller bug reddens it AND any-any-row search would miss the fresh 'thanks' row).
- Continuations: resultRow() is Role::Assistant (TranscriptProjector) so the step-loop/host re-entry walk skips all tool rows and re-finds the typed row; testToolContinuationsOfTheSameTurnKeepTheTier models the real re-entry (scheduleBackendCompletion callers :3748/:11438). Real.
- Typed-row-only: predicate == production settle shape (Message.php:941). Injected exclusion legs NOT pinned — see MINOR-2.
- Fence/backtick: 12-row table covers inline, double-inline, ```/~~~ fences, unpaired fence (swallow, fail-closed), reopened fence, unpaired backtick (fail-open prose). quoted-word dispatch pin exists.
- Non-shaper: verdict-gated; exactly one notice row 'ultrathink: scripted/m …normal tier' via $notices?->record, typed-row-last once-guard; continuation re-entry asserts no second row. Null-sink drops (E199 law honored). M3-shaped claim plausible; the repeat-leg pin is real.
- No persistence: no persistEffort/settings write in block; ConfigWriteProducer guard green. "Restart = normal tier" is by-construction (stateless re-derivation).
- Top-tier legality: levels() last='max'. OpenAI maps max→high (openAiReasoningEffort, live), Sglang accepts max (roster) / maps→xhigh on Qwen3-Next, Codex/ClaudeCode accept max, Anthropic/Gemini/Bedrock/Vertex 32768 budgets pass through capToCeiling at wire sites (AnthropicMessagesWire:527, GeminiWire:258/283, Bedrock:860). Clamp machinery pinned by C-PRV-1.a/5.a lanes' own tests (green bands). OK.

## Scope/conventions
- 7 files == declared 5 + PathGlob (§1.8) + in-branch REPORT.md. TurnRunner hunks both inside start() (imports + :417-468 before the return). Zero protocol/schema/generated outputs. SETTINGS.md is hand-prose (brief-sanctioned); guards green. final/strict_types/one-type/Lang::t all conform; test file has no glob-shaped or Refused-shaped literals.

## Findings
- MINOR-1 (step-local): latest-typed-row walk is the tree's 3rd-4th copy of one predicate (Message.php:941, SweepCommand.php:134, TurnRunner.php:1082 open()) — and open() ALREADY computes this same $prompt over the same $history a few lines earlier in the very call path (start() :384 → open()). Extract a shared finder (e.g. private static latestTypedRow(): ?Message used by both TurnRunner sites, ideally a Message::helper for the tree). Suggested weld-cure is a follow-up fold step, not a weld edit into the open() region (claimed-region discipline; other W22 lanes may touch TurnRunner).
- MINOR-2 (step-local): deleting `!$row->uiOnly && $row->userVisible` from the lane predicate leaves all 17 tests green — the injected/hidden-row exclusion is asserted only in prose. Add one dispatch pin: history [Message::user(...clean), new Message(role: Role::User, content: 'ultrathink inside injected context', createdAt: time(), userVisible: false)] on the shaper → dispatched tier stays configured.
- NIT-1: fence run-length deviation (a 3-backtick run closes a 4-backtick opener; strpos can land an N-run inside a longer run) — exotic fail-open; docblock says "its closing one". Optional length-aware close.
- NIT-2: `use` insertion order (Lang after Message; Providers before Permissions) breaks the file's alphabetical flow; no ordered_imports fixer rule, cosmetic.

## Weld carries (confirmed owed)
- durations.tsv row tests/Host/UltrathinkTurnTest.php; suite-figure/README +17T re-pin; §4 mark "✅ 01c230f8c C-PRV-3.c". Foreign carries stand: six orphan keys + GlobDialect staleness pair belong to their owning lanes/closeout.
