# C-TOOL-3.a — Round-1 review

VERDICT: FINDINGS — 1 CRITICAL, 1 MAJOR (both step-local, block weld); notes below.

Worktree /home/sites/cc-cmp-C-TOOL-3.a, branch cc/C-TOOL-3.a.

## Findings

- [CRITICAL] branch tip — claimed commit `652993e94` DOES NOT EXIST: `git cat-file -t 652993e94` fails in the worktree AND in /home/sites/sugarcraft (shared object DB); branch tip == base `ebf05989e`. All five lane files exist ONLY as uncommitted dirt (`M PtyDriver.php`, `M PtyRun.php`, `?? src/Tui/ToolTailSlots.php`, `?? tests/Tui/ToolTailSlotsTest.php`, `?? tests/Tools/PtyRunToolProgressTest.php`). The lane REPORT.md was never written (lanes/C-TOOL-3.a/ holds only BRIEF.md). Fix: make the single cohesive commit per §1.3/§1.4 author law (split-var, %ae/%ce verify, zero trailers) and write REPORT.md listing the two new test files for the durations gate. Nothing can be welded or cherry-picked from an empty range.

- [MAJOR] candy-code/src/Tui/ToolTailSlots.php:10-22 — docblock teaches wiring that does not exist in present tense: "A ToolProgress frame lands here from the turn's event fold", "the Renderer's running-tool row peeks the slot on every paint", "every slot is cleared when its tool call settles (replaceToolRunningPlaceholder) and on session resets". Production references: ZERO (grep `ToolTailSlots::` across src → only the class file; peek/set/clear/clearAll have no caller). The mirror source disproves the claim by contrast: PreviewSlots IS wired — set at Chat.php:3743/:4270, clear at Chat.php:4390; ToolProgress's real folds (same lines, ToolProgress arm) deliberately only `recordEvent`, with the in-tree comment "The TUI paints no live tail this wave". Fix: flip the docblock to name C-TOOL-3.b as the step that wires fold→set, paint-peek and settle-clear (or land those three edits now — but Chat.php/Renderer are outside this brief's declared files). Step-local, wording fix; weld must not ship the false-tense paragraph (doc-truth is a MAJOR class in this program — precedent cc-compare-audit-1021 class B).

## Category notes (verified, no finding)

1. Scope == brief: 5 files, exactly the brief's create/modify list. The orchestrator's "modified PtyRunToolTest (+1 test)" and "4 NEW test files" do not match disk: PtyRunToolTest.php is untouched and only 2 test files exist — the new behaviour is pinned in PtyRunToolProgressTest (5 methods). Flag to the lead if the +1-in-PtyRunToolTest leg was design-binding; the new file covers the same ground. No serialized wire fields (ToolProgress + encodeEvent arm pre-existing; store is in-process only). No dist/suite-figure/durations/protocol-generated files in the dirt.
2. Store semantics: seq guard `current.seq >= seq → drop` (ToolTailSlots.php:47) rejects lower AND equal (replay). Mutation-reasoned both directions: `>=`→`<` reddens test:58 and test:62 legs; rejecting-fresh reddens test:65. MAX_SLOTS=8 array_shift eviction = oldest-insertion, identical to PreviewSlots:41-43. Test setUp/tearDown clearAll matches the mirror's house pattern (PreviewSlotsTest:18-25) exactly — no drift.
3. DEVIATION (accepted): chunks route Ansi::strip → ShellTailRing → ToolProgress frames — Bash.php:523-543's exact contract incl. the forged-id door (`$toolCallId !== ''`, TakesToolCallId overwrites `$args['id']`, Runtime side) and Bash's shared consts (PROGRESS_TAIL_BYTES 4096 / 0.5 s). The orchestrator's "withShellOutput" label matches no symbol tree-wide (grep zero hits) — the implementation follows the BRIEF, not that paraphrase. UI reach today: serve/web (TranscriptProjector:527 TOOL_PROGRESS, StreamJsonEmitter:123); TUI paints no tail this wave by pre-existing comment — single path, no duplication. Final result frame byte-untouched (render() unchanged; PtyRun:473; pinned by test:68 `green-live` in content, test:108 raw \x1b kept in transcript).
4. Wiring: EngineBackend::turnTools :3222-3280 NOT in the diff (status proves file untouched); pid-gated emitter binds via interface, PtyRun's withToolProgressEmitter is a byte-shape mirror of Bash:119 (get_object_vars + named-spread). Non-vacuous pin: testARealRunFramesItsStrippedTail drives a REAL pty spawn asserting frames, strictly-rising seq, no \x1b leak, plus the no-pty polarity branch.
5. Child-process safety: diff adds zero spawn/close sites — onChunk feeds inside collectWindow BEFORE retain (:294-300) and inside the exited-branch drain (:309-316); teardown ladder untouched. Builder's "20 clean runs" claim unverifiable (no report/logs) — reviewer spot-check: PtyRunToolProgressTest run 4× consecutively, all green (5T/22A each).
6. Gates (all plain-pipe, cwd worktree candy-code, env -u LINES -u COLUMNS, targeted only; no wholesale PtyRunToolTest filter run):
   - ToolTailSlotsTest 5T/20A green; PtyRunToolProgressTest 5T/22A green ×4;
   - 'ToolTailSlots|AcceptsToolProgress|PtyDriverWindow' 7T/32A green (30T builder claim re-run NOT possible without the forbidden wholesale filter — disclosed);
   - 'Bash' 508T/2216A green; 'ToolCatalogTest|ToolDocDriftTest' 15T/152A green (builder's 15T/72A figure differs — by-design assertion wobble or narrower set; outcome green);
   - five-guard 133T, ONLY the staleness pair red (25,537 pinned vs 26,201 live — weld re-pin duty, pre-existing base drift);
   - DescriptorInheritanceGuardTest 15T/5033A green; tools/check-child-lifetimes.php refused by reviewer shell policy — structurally covered by finding 5 (zero new spawn sites).
   - "PHPUnit Deprecations: 2" appears on EVERY run including base-tree mirror tests → pre-existing config noise, not lane-introduced.
7. DUR: new test files needing durations.tsv rows at the gate — exactly `candy-code/tests/Tui/ToolTailSlotsTest.php` and `candy-code/tests/Tools/PtyRunToolProgressTest.php`.
8. Author law: NOT APPLICABLE — there is no commit to audit (finding 1).

## Weld disposition
BLOCKED until the commit + REPORT.md land and the ToolTailSlots docblock truth-flip ships (or 3.b wiring lands in-step). After fix: re-review by a fresh reviewer (§1.6).
