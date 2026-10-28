# REVIEW-1 — C-MCP-2.a (round 1)

VERDICT: FINDINGS — 0 CRITICAL / 1 MAJOR / 0 step-local MINOR / 2 NIT

## Scope/traceability
- One commit b41c22b95 on base b38baf0f8 (C-SES-9.b 307ffb44f is an ancestor of the base — no collision). master (0b9c66157) moved +3 commits since base; name-only diff shows master touched NONE of the lane's 8 files → rebase risk nil.
- Files: exactly the brief's list (SessionHost.php, NonInteractive.php, McpPromptCommands.php, docs/MCP.md, 3 test files, DocFigureProseDriftTest). Chat.php UNTOUCHED (claim 3 verified). Forbidden files (suite-figure.json, durations.tsv, dist) untouched.
- Author law: %ae and %ce separate greps both detain@interserver.net; zero trailers in body.
- Regions: hunks sit in ctor/new/admit(custom-command branch)/parkExpansion/admitPrompt-call; imports + one property block adjacent to ctor state. Deviation #1: dispatch() one-line `mcpProvider:` named arg — JUDGED ACCEPT: admitPrompt delegates through dispatch; the design's Approach-4 ("admitPrompt passes mcpProvider") lands exactly here; one line, minimal, and current master has no in-flight SessionHost overlap.

## Correctness traces
- Claim 1: ctor `$mcpProvider ?? static fn () => Bootstrap::mcpClient($root)`; sole ctor call site is new(); sole production SessionHost::new caller (SessionHub.php:102) omits the arg → default. Bootstrap::mcpClient public static (:8356). Lazy: specs()/materialize() resolve on demand, matching Chat's mcpClientProvider shape.
- Claim 2: NonInteractive chains ->withMcpReader over Bootstrap::mcpClient($mentionRoot); refusal literal byte-identical to TurnController.php:1098 ("no MCP client is open for this project"). Run output confirmed the refusal notice shape.
- Claim 3: mergeUnderDisk = array_merge($mcpSpecs, $diskSpecs), same spelling as Chat.php:10110; DocFigure pins both spellings + order.
- Claim 4: sync path materialize→admitExpanded($text,'')→existing empty-refusal; park path materializes INSIDE $run (fork child under pcntl; $run is also the inline fallback) and returns ['',$gated] → resume's new empty→notice leg. Note the pre-change parked resume DISCARDED the refused TurnTicket (silent vanish) — deviation #2's visible notice is required for the docs' "leaves a visible refusal" promise and mirrors Chat; JUDGED ACCEPT.
- Claim 5: materialize keeps tier 'mcp' (McpPromptCommands.php:173) → CommandShellPosture::of → ShellProvenance::Mcp → customCommandMustFork returns true unconditionally (fork posture) and refuseCommandShell refuses every shell form (C-SEC-3 intact; sync leg only runs when canFork() false — the same shape Chat has). Directive built from the MATERIALIZED spec (fetch judged before shell scan).
- E646: no new timers on the fetch (rides server per-call bound); test drain timers are test-side.

## Tests/gates (reproduced exactly)
- 'SessionHostMcpSurfaceTest|NonInteractiveMentionsTest|TurnControllerMcpSeamTest|ContextMentionsTest|McpPromptCommandsTest': 40T/199A OK (2 deprecations pre-existing family).
- 'DocFigure|StderrEmitterCensus|ReadmeRosterDrift': 196T/24725A 3F — F set = the 3 StderrEmitterCensus standing reds. Proven base-inherited, not lane: NonInteractive fwrite(STDERR sites byte-identical across b38baf0f8/master/tip (line-shift only); StderrEmitterCensusTest not in the diff.
- Non-vacuity: surface test drives real SessionHost::submit + forked park against planted McpClient; pre-fix the mention leg had no reader (no attachment), no MCP rows (PENDING impossible), parked empty-refusal was silently dropped (settled-row assert impossible). Disk-shadow test pins merge order via fetches===0.
- ADDITIONAL guard run: GlobDialect/SymbolCitation clean; TreeWideGuardRoster 2F = standing reds; ReadmeSuiteFigureDrift staleness pair expected (+8T, weld re-pin).

## FINDINGS
- MAJOR — candy-code/tests/Host/SessionHostMcpSurfaceTest.php:261 — drain() is a one-token-divergent copy (guard timer 20.0 vs 15.0) of tests/Host/ServedProjectCommandTrustTest.php:211 drain(); reddens tests/Support/DuplicatedTestHelperDriftTest::testNoCopiedTestHelperHasDriftedUnrecorded tree-wide ("drain: Host/ServedProjectCommandTrustTest.php [261:15.0] vs Host/SessionHostMcpSurfaceTest.php [261:20.0]" in the unrecorded set). Not on the standing-red list; the lane's gate filters omitted this guard. Fix in-step: make the new drain() byte-identical to the sibling (15.0 — or move both to a shared value only if the fetch timing needs it) OR add a recorded ACCEPTED_DIVERGENCE row with the reason; re-run the filter to green.
- NIT (out-of-scope/queue) — src/Cli/NonInteractive.php:462 vs src/Host/TurnController.php:1098: the refusal literal is duplicated by copy; only the controller wording is test-pinned (TurnControllerMcpSeamTest:83). A shared constant would keep the parity promise enforceable; design itself mandated identical wording, so queued not step-local.
- NIT (weld duty) — new file candy-code/tests/Host/SessionHostMcpSurfaceTest.php needs its scripts/parallel-tests-durations.tsv row at weld (lane correctly never touched it); suite-figure re-pin carries +8T/+~150A.
