# W26 wave review 1 — verdict-first

WAVE-VERDICT: FINDINGS — 0 CRITICAL / 1 MAJOR (C-CTX-6.e never landed) / 0 wave-local minors; other 9 steps CLEAN on merged tree @ 12e70fdc9.

Reviewer: cc-cmp wave-reviewer, read-only; master tip at start = d7a2efbf1-era, re-read tips = 12e70fdc9. Gates run cwd=candy-code -c phpunit.xml plain pipe.

## 1. §4 mark integrity (ROOT plan_comparison.md, anchored ^#### ✅)
- 9/10 marks anchored, all cat-file OK + ancestor of master: C-SEC-7.e ✅c9e5cbde3 · C-SES-6.e ✅7240c63f3 · C-SES-8.e ✅2f701f308 · C-TOOL-2.f ✅5419a4f80 · C-TOOL-2.g ✅06d7a9858 · C-TOOL-6.a ✅9c887f03b · C-TUI-2.f ✅093cd25e1 · C-TUI-2.g ✅85463f3fd · C-TUI-3.a ✅a725ebc11.
- **MAJOR: C-CTX-6.e** — plan:3119 carries a PLAIN unmarked `#### C-CTX-6.e — Structured, expandable recall row` heading. Zero occurrences in run-state.json; not in welded[] (272); no cc/C-CTX-6.e branch or worktree; no `recordStructured` in RuntimeNoticeSink blob (git show proof); no RecallNoticeRowTest in tree; no commit mentions the id. Spawn-brief claim "all 10 welded per run-state" was FALSE for this step. Nothing landed — the design (RuntimeNoticeSink::recordStructured JSON datagram, memory.recall {count,titles[]}, tests/Chat/RecallNoticeRowTest.php, docs/MEMORY.md wiring) is fully absent.

## 2. Landed-blob proofs (git show <sha-or-HEAD>:path, LANDED-BLOB law)
- C-SES-8.e: ParsedArgs SUBCOMMANDS contains `usage` at master (mark points at master picks 1b48926b7/2f701f308 — landed independent of the contaminated C-TOOL-6.a worktree ✓).
- C-SES-6.e: src/Cli/UpdateCheck.php + tests/UpdateCheckTest.php exist @HEAD.
- C-TOOL-2.g: OpenAIProvider responsesToolResultDocumentBudget :436 + responsesDocumentModelGate :448 (accepted deviation) exist.
- C-TUI-3.a: Chat.php handleCopyCommand :12682; ARCHITECTURE.md:155 "dispatch arms for 65" matches live era; /copy row in slash help.
- C-TOOL-6.a: docs/MCP.md `Browser control and computer use` grep -c == 1 (single section, no dup).
- C-TUI-2.g: schema.json checkpoint count 8, generated.ts 7 — regen pair coherent (ProtocolSchemaDriftTest 7T/303A OK).
- C-SEC-7.e: Bootstrap.php :7429-7496 conditional autoReview default per ruling option-3 — claim satisfied.
- No welder self-phantom found on the 9 landed steps.

## 3. Test landing / durations.tsv (HEAD blob)
All rows present: UsageSubcommandTest 1.155/9 · BootstrapPermissionGateTest 0.803/68 · McpToolBridgeImageChannelTest 0.208/15 · AutoReviewScopeTest 0.068/15 · ResponsesToolResultMediaTest 0.040/10 · CopySlashCommandTest 0.024/7 · CopyCommandTest 0.011/15 · FilesDiffCheckpointTest 1.919/9 · UpdateCheckTest 0.035/18 · McpBrowserPresetDocTest 0.009/4 · StartupSummaryTest 0.565/18. No 10x outliers. (RecallNoticeRowTest absent = part of the MAJOR.)

## 4. Cross-lane + guards (merged tree)
- ReadmeRosterDrift|CommandDocsDrift|DocFigureProseDrift: 108T/4256A OK.
- EnvRosterDrift|TrustKeyDrift|DuplicatedTestHelperDrift|GlobDialect|KeyBindingDrift: 298T/43022A OK — DTHD GREEN proves C-TUI-3.a OPEN/CLOSE/STUB_CANDIDATE roster cure a1b075d1e effective.
- Per-step lane filters (Copy pair, UsageSubcommand, UpdateCheck, McpBrowserPresetDoc, FilesDiffCheckpoint, ResponsesToolResultMedia, McpToolBridgeImageChannel, AutoReviewScope, BootstrapPermissionGate): 170T/684A OK on merged tree.
- StderrEmitterCensusTest 3F @HEAD — PROVEN introduced by FOREIGN pick d7a2efbf1 (C-TOOL-5.d, W27-era): Subcommands.php blob `candy-code: ` 46→51 and STDERR occurrences 8→9 across that single commit, roster untouched at 46/7. W26-local chain was in-step (C-SES-8.e 44→46 landed). Ledger note → C-TOOL-5.d's wave review.
- HelpTest 6F (CANDY_CODE_SERVICE_TIER unrostered — introduced by foreign 36cc39ad1 C-PRV-3.a) + KeyHelpTest 5F+risky: both listed in run-state standing_reds (foreign, never-accuse honored).
- Authors: all 17 W26 commits %ae/%ce = detain@interserver.net. E646/fork discipline: no blanket-timeout or child-discipline defects seen in the 9 landed diffs' promised surfaces.

## 5. C-TOOL-6.a worktree duty
/home/sites/cc-cmp-C-TOOL-6.a branch+worktree KEPT by batch1030o_note (carries foreign uncommitted C-SES-8.e work). Not accused, not proposed for pruning.

## 6. COMPARISON.md doc truth
No wave mark promised a COMPARISON flip; PLAT-07 owns cell flips. Ledger note for closeout: UI-16 candy-code cell still 🟡 post-/copy, TL-21 cell ❌ now understates the documented MCP browser-preset route — both belong to the PLAT-07 re-derivation.

## 7. Ledger notes (closeout-only, non-blocking)
1. C-TOOL-5.d StderrEmitterCensus cure owed on master (3F, incl NUMBER_WORDS thirty-six→37).
2. C-TUI-2.f README:241 'lazy' stale doc-row carry (closeout).
3. PLAT-07: UI-16/TL-21 cell re-derivation.
4. C-SES-6.e mark is bare single-line (style nit only — anchored and truthful).
