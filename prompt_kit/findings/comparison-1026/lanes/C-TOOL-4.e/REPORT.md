# C-TOOL-4.e — Jail LSP and MCP stdio servers (lane report)

Lane /home/sites/cc-cmp-C-TOOL-4.e, branch cc/C-TOOL-4.e, base 895b9d6fd.

## Pick 1 — d5112901b (LSP half + scope/lang)
SandboxScope gains SCOPE_LSP/SCOPE_MCP (SCOPES six, help order; docblock
truth-flips four->six); lang/en.php settings.sandboxScope.help rewritten to
six scopes + opt-out sentence, THREE new keys inserted in the sorted run
between sandbox.hook.not_run and sandbox.runner.skipped (SORT_STRING law):
sandbox.lsp.not_started, sandbox.mcp.not_started, sandbox.mcp.opt_out_ignored.
LspConnection: promoted array $spawnPrefix = [] 3rd ctor param (list<string>,
PREFIX-ONLY note), connect() prepends inside the existing
ProcessContainment::spawnSpec site; E672 pre-check still names $command.
LspLauncher: withSandbox(?SandboxBackend,?string):self clone-shape (install
before launch, 4.d HookManager posture); per-server 'sandbox' key (ONLY
literal false opts out — junk jails, strictest reading); AUTO_TABLE rows
'sandbox' => true; launch() refusal => Lang sandbox.lsp.not_started problems()
row + continue (never started, never unconfined), jailed prefix =
argv($this->root) so writable root == --chdir cwd == project root; default
closure gains 3rd $prefix=[] param (2-param test fakes still mount).
Gate pre-commit: (LspLauncherTest|LspHandshakeBudgetTest|SandboxScope|ScopedRunnerJailTest)
OK 24T/181A (2 pre-baseline PHPUnit deprecations).

## Pick 2 — 4c7123545 (MCP half + wiring + docs)
StdioMcpServer adapter: trailing promoted spawnPrefix=[], spawnPlan()
prepends inside spawnSpec (library proc_open / DI guard / child-lifetimes
row ride untouched). McpClient: 4 private props + withSandbox() in-place
(setDenyPatterns install-before-start posture) + sandboxOptOutNotice()
(strippedSecretEnvNotice house shape) + stdioJailPrefix() helper:
user-tier-only opt-out (project-tier recorded + FALLS THROUGH jailed),
refusal throws before construction (startServer config-error report =
fail closed), argv(sandboxRoot, chdir: $cwd) — user-tier $HOME cwd
survives via --chdir without $HOME writable. stdio arm gained named arg
spawnPrefix: $this->stdioJailPrefix(...) INSIDE new StdioMcpServer( —
'stdio' => new StdioMcpServer( adjacency preserved, $config['sandbox']
literal inside the arm slice, resolveEnv census untouched ['env','headers','headers'].
Bootstrap lspClient()/mcpClient() install both jails (same-$config pairing
law; $trustRoot writable). ToolSettings readBy gains server chains.
Docs: MCP.md Keys cell + Jailed servers section (cache recipe: /tmp is the
jail's private tmpfs; ${TMPDIR} spelling interpolates from the PARENT env,
so the doc says write the literal path); PERMISSIONS.md bullet covers
lsp/mcp fail-closed + tier opt-out; HOOKS.md per-server keys gain sandbox
(DEVIATION disclosed: design named README/SETTINGS for the lsp entry keys —
the enumeration home on disk is HOOKS.md; README sandboxScope prose gained
the servers phrase too); gen-settings-doc --write rewrote SETTINGS.md
:266 reader row, --check rc0; gen-command-docs/gen-tool-docs --check rc0.
Gates: DocFigure MCP arm 1T/53A GREEN (Keys-cell pairing proves in-step);
doc-guard quartet 47T/907A GREEN; LayeredSettingsTest 99T/411A GREEN
(derived==pinned, no new settings key); %ae/%ce greps 1/1; porcelain 0.

## Disclosed seams (not defects)
- SandboxFactory::forScope docblock sentence still enumerates only
  hook/lint/format/test readers — file is NOT in this step's design files
  list; the lsp/mcp readers are documented in ToolSettings/SETTINGS.md and
  the SandboxScope class docblock instead. Report-only.
- sandbox.mcp.not_started's {mode}+{name} render the backend mode/name in
  the throw message; the LSP twin rides problems() verbatim.

## §7 HAND-OFF (LISTED NEVER FIXED — gate mints)
- NEW test file tests/Tools/Sandbox/ServerJailTest.php — durations.tsv row
  owed at battery with LIVE counts (see pick 3 section when landed).
- suite-figure.json/README staleness pair carries this lane's new-test-class
  share at battery re-pin; lane NEVER touched the pinned files.
- check-child-lifetimes sugar-mcp-client/src/StdioMcpServer.php::start row
  re-run result recorded in pick 3 section.


## Pick 3 + history correction (2026-10-31, lane builder)
ERRATUM: pick 2 was REBUILT locally before this report's close — original
4c7123545 folded to 340876035 so the DocFigureProseDriftTest bodyExcerpt
widening (3000->3600 on buildServer) rides the SAME commit as the src
arm lines that pushed the headers-optional needle out of the window
(§1.8 in-step law). Branch tip history NOW: d5112901b (lsp scope src+lang)
/ 340876035 (mcp scope src+Bootstrap+ToolSettings+docs+regen+DocFigure
widen, 10 files +235/-15) / 027ef2464 (server-jail pins, 4 test files
+459, new tests/Tools/Sandbox/ServerJailTest.php). All three cat-file
commit, %ae/%ce grep -c detain = 1/1 each, zero Co-Authored-By, master
still 895b9d6fd at every commit; git cherry master cc/C-TOOL-4.e = 3x '+'.

Gates at tip (plain pipe, cwd=candy-code, -c phpunit.xml):
- (ServerJailTest|ScopedRunnerJailTest|LspLauncherTest|LspHandshakeBudgetTest|StdioMcpServerTest|McpTrustPinsTest) OK 63T/331A (2 pre-baseline deprecations)
- ServerJailTest alone OK 8T/57A; family superset incl McpClientTest OK 129T/465A
- DocFigureProseDriftTest FULL class OK 92T/4236A; the two MCP arms alone OK 2T/106A
- (SettingsSchemaDocDrift|CliLangCatalogue|LangParity|EnvRosterDrift|ConfigWriteProducerDocumentationDrift|LayeredSettings) OK 194T/46,315A
- TreeWideGuardRosterTest BARE OK 17T/3,922A; DuplicatedTestHelperDriftTest BARE OK 14T/204A (helper names distinct from ScopedRunnerJailTest jailRoot/jailRunFixture/spawnShapeOf)
- (DescriptorInheritanceGuard|SuiteSkipRoster) OK 43T/5,369A — NO new roster row (PREFIX-ONLY)
- php tools/check-child-lifetimes.php rc0 (sugar-mcp-client StdioMcpServer::start row unchanged)
- generators gen-settings-doc/gen-command-docs/gen-tool-docs --check rc0 (SETTINGS.md reader row regen rode pick 2)

Mutation EXECs (cp+md5 restore verified both):
- strip spawnPlan prepend (StdioMcpServer.php) -> EXACTLY 1 red:
  testTheSpawnPlanPrefixesInsideTheContainmentWrap
- strip LspConnection connect() prepend -> EXACTLY 1 red:
  ServerJailTest::testTheRealSpawnLineCarriesTheJailPrefix... (jailer
  argv-dump leg — real exec, log shows prefix absent)
Reflection-EXEC pins (run the real private seams, not static reads):
stdioJailPrefix door legs (user-tier honor / project+unknown-tier
ignore-with-notice / refused-throw / argv(root,chdir)) and parseServer
literal-false leg. Out-of-scope byte-identity is pinned BOTH ways:
construction (empty-prefix spread collapses) AND the pre-existing
testTheSpawnPlanIsTheContainmentWrapAndScrubVerbatim verbatim plan leg.

§7 HAND-OFF (LISTED, NEVER FIXED here): durations.tsv row for NEW file
candy-code/tests/Tools/Sandbox/ServerJailTest.php at LIVE 8T/57A;
suite-figure.json/README staleness pair carries this lane's +10T class
(+8 new + 2 modified-file methods: LspLauncherTest +1, StdioMcpServerTest
+2, McpTrustPinsTest +0 provider-row-in-existing-test — provider rows do
not add methods; battery re-pin owns figures). COMPARISON jail cells
candidates for closeout. Foreign reds NOT adjudicated this tick (roster
unchanged-shape assumption; battery will surface any delta).

Deviations ledger (final): (1) HOOKS.md carries the lsp per-server `sandbox`
key enumeration (design said SETTINGS/README; the enumeration home on disk
is HOOKS.md — SETTINGS/README gained the server wording via regen+prose).
(2) SandboxFactory.php NOT touched (outside design files list): its
forScope docblock reader enumeration still names the 4.d four readers —
report-only seam. (3) lang keys sandbox.lsp.not_started /
sandbox.mcp.not_started / sandbox.mcp.opt_out_ignored all landed in pick 1
(one SORT_STRING run between sandbox.hook.not_run and sandbox.runner.skipped)
so pick 2 carried no lang delta. (4) McpClient stdio-door legs use
reflection on stdioJailPrefix rather than the full startServer IO path
(deliberate: keeps ServerJailTest hermetic; startServer's catch->report
channel is pinned by the throw-type/message contract leg).
