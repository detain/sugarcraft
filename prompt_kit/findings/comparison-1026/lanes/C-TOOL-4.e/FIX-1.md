# C-TOOL-4.e fix ledger

## Round-1 fix
FIXER round 1 · 2026-10-31 · LANE=/home/sites/cc-cmp-C-TOOL-4.e · branch cc/C-TOOL-4.e.
Base of fix: reviewed tip `027ef2464` (3 picks over `895b9d6fd`, untouched); new tip `55a934ef0` — ONE separate follow-up commit, the three reviewed picks never amended. picks=4 · porcelain 0 · %ae/%ce 4/4 detain (separate count greps) · Co-Authored-By 0.

### MINOR (step-local) — FIXED
r1 finding: `McpClient::stdioJailPrefix` recorded project-tier false opt-outs even when `sandboxJail === null` → drained notice claimed "The server(s) jail as scoped." with no jail active.
Cure (`candy-code/src/MCP/McpClient.php`): recording leg gated on `$this->sandboxJail !== null` (r1's exact prescription); method docblock truth-flipped (recording happens "while a jail is active"; no-jail bullet now states nothing is recorded — the notice must never claim a jail that is not there).
Pin: new `ServerJailTest::testTheOptOutNoticeStaysSilentWhenNoJailIsActive` — both polarities, ONE client: project-tier false + NO jail ⇒ [] prefix + EMPTY notice; jail installed (`withSandbox` is a void in-place mutator) ⇒ non-[] prefix + notice names `projSrv`.
EXEC status: **EXEC'd, red-before-then-green confirmed** — pre-fix run `Tests: 9, Assertions: 60, Failures: 1` with the failure message printing the false "jail as scoped" claim verbatim; post-fix `ServerJailTest::` = `Tests: 9, Assertions: 62, OK` (baseline 8T/57A + 1T/5A new leg exactly).

### NIT — recorded (this section is the record; REPORT.md history NOT rewritten)
**WELDER §4 MUST CITE THIS CORRECTION:** builder pick-1 `d5112901b`'s commit message says "lang: … new sandbox.lsp.not_started (the mcp keys land with the MCP slice)" — disk truth (git show, re-proved this round): ALL THREE sandbox lang keys (`sandbox.lsp.not_started`, `sandbox.mcp.not_started`, `sandbox.mcp.opt_out_ignored`) rode `d5112901b`, and pick-2 `340876035` has an EMPTY `candy-code/lang/en.php` diff (0 lines). Harmless — single landing preserves the one SORT_STRING run — the message claim is false, the commit content is right. Do NOT rewrite pick-1 (reviewed-pick law); ledger note only.

### Gates (all cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe, anchored full-Test-suffix stems)
- `ServerJailTest::` = Tests: 9, Assertions: 62, OK (2 pre-baseline deprecations).
- `(ServerJailTest|ScopedRunnerJailTest|LspLauncherTest|LspHandshakeBudgetTest|StdioMcpServerTest|McpTrustPinsTest)::` = Tests: 64, Assertions: 336, OK — r1's 63T/331A + exactly the new leg.
- `McpClientTest::` = Tests: 66, Assertions: 134, OK, Skipped: 1.
- `(CliLangCatalogueTest|LangParityTest)::` = Tests: 15, Assertions: 4,206, OK (r1-era exact; zero new keys — `git diff 027ef2464 -- candy-code/lang/en.php` = 0 lines, byte-untouched).
- BARE `TreeWideGuardRosterTest::` = Tests: 17, Assertions: 3,922, OK; `DuplicatedTestHelperDriftTest::` = Tests: 14, Assertions: 204, OK.
- `LayeredSettingsTest::` = Tests: 99, Assertions: 411, OK (no settings key added).
- Diff shape: 2 files, +31/−6 (McpClient.php docblock+gate, ServerJailTest additive new method).

### Substrate law re-proof
`git diff 895b9d6fd -- src/Tools/Sandbox/SandboxFactory.php src/Hooks/ScriptHook.php src/Tools/BuiltIn/Bash.php src/Hooks/HookManager.php` = 0 lines (all four paths ls-files-confirmed real first — a wrong path would be silently empty; Bash.php lives under Tools/BuiltIn/). Fix touches only McpClient + ServerJailTest.
Excluded/honored: PtyRunToolTest::testExpectMissIsLoudAndTheScriptStillContinues (never in any filter), RegistryTest O_TRUNC + ForkedChildReaper report-only. No forbidden surface touched (suite-figure.json, durations.tsv, dist, protocol trio, plan/run-state files, .opencode/**).
