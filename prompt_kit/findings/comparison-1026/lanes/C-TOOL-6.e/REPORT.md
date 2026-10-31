# C-TOOL-6.e — User-tier script tools from `~/.candy-code/tools/*.json`

STEP=C-TOOL-6.e LANE=/home/sites/cc-cmp-C-TOOL-6.e · branch cc/C-TOOL-6.e · background=true.

## Verdict
SHIPPED. Pick `fcf16d15e2335ca896ff4291c204f4c30bf33c0b` (cat-file -t = commit, citing shell),
base d1cd13055 (= master at commit time, branch HEAD, own picks 0 before, porcelain 0 after).
Author/committer both Joe Huss <detain@interserver.net> (%ae and %ce verified by SEPARATE
grep -c counts = 1 each), zero Co-Authored-By trailers. 24 files, +1846/−31.

## What landed (design block, as written)
- `src/Tools/UserTools/UserToolSpec.php` — validation VO: name `^[a-z][a-z0-9_-]{0,47}$`,
  description 1..1024 bytes, inputSchema `type:object`, argv non-empty string list, element-0
  placeholder refusal, every `{{prop}}` declared, timeout int 1..600 default 60. Static
  `refusal()` returns one class word or null.
- `src/Tools/UserTools/UserToolLoader.php` — reads `HomeDirectory::owned() . DIRECTORY` once
  (:84 scandir, DIRECTORY='.candy-code/tools' so no glob-shaped literal); project tier NEVER;
  bad files/duplicates → skip notices (Lang `cli.usertools.skip.*`, keys literal for the
  catalogue scan); unowned home → nothing, quiet.
- `src/Tools/UserTools/UserScriptTool.php` — NAME_PREFIX `user__`, wire `user__<name>`;
  implements AcceptsHeartbeat + AcceptsWorktreeJail; renderArgv substitutes scalars, drops
  exact-absent-optional elements, refuses collections (:200-253, EVERY element
  `escapeshellarg()` at :232/:253); `bash -c` + `ProcessContainment::cdGuard` (:127);
  bashSandbox wrap via SandboxFactory backend with jail/project root writable, probe-refusal
  fails CLOSED (:131/:146); `CapturesProcessOutput::runCaptured` with Bash output cap + the
  tool's own declared timeout (:149-156; E646: per-tool ceiling, design's own);
  `CANDY_CODE_TOOL_INPUT` JSON env export (:156); non-zero exit = error with stderr tail;
  timeout = error + group kill. No new proc_open token (runCaptured accounted).
- `Bootstrap::userScriptTools(?string $root)` memoised per pid|home|root mirroring
  mcpToolShape() (:9372); skip notices routed ONCE through the sanctioned
  `warnPermissionConfigInTranscript()` seam (:9390) — zero new emitters, zero new sprintf.
- `LaunchContext::unfilteredTools()` appends after the MCP arm (:1953) so
  allowed/disabled filtering reaches them.
- Permission twin: `Runtime::USER_TOOL_PREFIX` beside MCP_TOOL_PREFIX (:860) read by
  isWriteCapableTool (:1446); `PermissionGate::evaluateAuto()` user__→Ask BEFORE the
  classifier (neither strike nor safe call; breaker untouched, :1091); `isWriteTool()` user__
  arm (:1808) kept BEFORE the verbatim final `return str_starts_with($call->name, 'mcp__');`
  (:1812, RuntimeTest literal regex survives); rules beat mode → `Allow user__fmt` grants
  exactly one tool (pinned); plan/dont-ask Deny, bypass Allow (matrix pinned).
- Docs: PERMISSIONS.md write-class list + plan/auto cells + `user__*` auto-table row +
  breaker sentence + one-rule clause; PLUGINS.md '## User script tools' section + third
  declined bullet (bundles contribute no script tools).

## §1.8 in-step guard cascade (all same commit)
- `lang/en.php`: 11 `cli.usertools.skip.*` keys inserted in ONE SORT_STRING run between the
  cli markers (python dupscan: 666 cli keys, 0 dups file-wide, sorted-run True — anchors
  CliLangCatalogueTest + LangParityTest legs green). Help screen entry `CANDY_CODE_TOOL_INPUT`
  added to the `cli.help.screen` value at the 3-space column.
- ReadPathCensusTest: `UserToolLoader.php|file_get_contents` OWNED_HOME + `|scandir`
  NAMES_ONLY, tight prose, same commit as the sites.
- EnvRoster: new export (envOverrides array key) required a scanner channel — S6-child-env
  added to `tests/Config/Support/EnvReadScanner.php` (literal `NAME =>` inside a runCaptured
  call ⇒ exported, not read), with BOTH fixture polarities (positive runCaptured-key,
  negative foreign `=>` stays unresolved); EnvRosterDriftTest reverse arm widened to
  reads∪exported (guard moves with surface — disclosed deviation); ENVIRONMENT.md table row +
  Help screen entry so every downstream roster law stays closed.
- StderrEmitterCensus family: seam count 36→37 (Bootstrap const), prefixed roster
  Bootstrap.php 48→49, full PROSE_SITES tense-law flips (Chat.php :9501, Bootstrap :1763/
  :5714/:9228, RoutingTest :18/:22/:25/:228, McpToolWiringTest :1675, SETTINGS.md :1038 —
  'thirty-six tools' sibling rows = tool-count domain, untouched), NUMBER_WORDS +thirty-seven,
  census self-prose FORTY-EIGHT→FORTY-NINE + history parenthetical extended with this step.
- Word-numbers derived LIVE (the seam censuses are the derivation; no doc numeral moved
  without its generator agreeing). No new emitters, so the direct-fwrite roster did NOT need
  my hand. TreeWideGuardRoster, DuplicatedTestHelperDrift green.
- GlobDialect untouched: no glob-shaped literals added (DIRECTORY const composition;
  `/proc/[0-9]*/stat` avoided via scandir + ctype_digit).

## Gate ledger (targeted only; cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS pipe; every filter NON-ZERO)
- Own files per-file LIVE: UserToolLoaderTest 10T/78A · UserScriptToolTest 11T/37A ·
  UserToolPermissionTest 6T/20A (= 27T/135A combined; NEW test files for §7 durations mint).
- EnvRosterDriftTest:: 38T/42913A green (2 deprecations = suite-wide pre-existing).
- ReadPathCensus|CliLangCatalogue|LangParity|BootstrapLaunchFormat batch: 52T, 1F =
  FOREIGN doc-leg (README/CI/DOCKER/SETTINGS promoted-format roster diff, pages untouched by
  me — brief roster item, adjudicate-only).
- TreeWideGuardRosterTest (bare) 17T/3939A · DuplicatedTestHelperDriftTest (bare) 14T/204A.
- Corpus batch (BuiltInToolCorpus|BuiltInToolTest|BinCandyCodeWiring|ToolSchemaEncoding|
  PermissionGate|PlanModeToolRoster|UserToolPermission):: 1587T/8932A green.
- RuntimeTest via namespaced filter 153T/595A green (bare stem = ZERO trap);
  write-roster sub-run 20T/33A green.
- Docs batch (DocFigure|ReadmeRoster|PermissionsIntro|SettingsTier):: 107T/4345A green
  AFTER all doc/prose flips (re-verified post-edit).
- Seam batch (BootstrapTranscriptSeamCallSiteCensus|BootstrapLaunchNoticeRouting|
  StderrEmitterCensus):: 139T/23850A, 2F = BOTH foreign (PtyRelay.php 9 direct-fwrite sites +
  BinCandyCodeAutoloadGuard 'thirty-eight' prose = C-TOOL-6.j debt at base db1441a61-era;
  files untouched by me — adjudicate-only, reported never cured).
- HelpTest:: full class 106T/4093A 1F = CANDY_CODE_SERVICE_TIER foreign leg (brief roster).
- Pairing (BashSandboxTest|BubblewrapHardeningTest):: 39T/192A GREEN — expected shape held,
  no new finding.
- FOREIGN-RED roster otherwise untouched: suite-figure staleness pair (battery owns),
  GlobDialect 1F, keepWarm, KeyHelp, AttachPaging, OneSidedHome:435, SettingsEditor:189,
  AccessorDeprecation :168/:202.

## Pins: EXEC vs UNCONFIRMED-BY-RUN
- EXEC: injection stays one argv word (victim-file guard + literal stdout); env JSON
  round-trip; absent-optional element dropped; collection/undeclared/missing refused
  pre-spawn (marker file never touched); timeout breach + group-kill descendants scan + wall
  bound; non-zero exit stderr tail; cwd=root and jail-wins; heartbeat fires; sandbox wrap leg
  via SandboxBackend double (wrap applied, marker still touched) and probe-refusal leg
  (errors without spawning, wrap would self::fail); loader validation matrix ×16 shapes;
  project-tier never read; unowned home → nothing; duplicate names the first; frozen roster +
  announce-once; launch-roster reachability (public method = corpus DYNAMIC_TOOL_CLASSES
  evidence); permission matrix/breaker/single-rule/literal-sentence source pins; EnvRoster
  S6 both polarities; en.php sort/dup legs via guard runs.
- UNCONFIRMED-BY-RUN (honest): a real Bubblewrap-backed wrap end-to-end (the box has
  /usr/bin/bwrap but real-backend integration is the WELDED 4.d/4.g pinned machinery — my step
  proves the tool routes through SandboxFactory's object and refuses closed on probe failure
  via the double); the stderr once-only channel beyond the notice store (owned by the
  sanctioned seam's own pins); scanner S6 beyond the fixture pair.

## Collisions / deviations
- No collisions: cc/C-TOOL-6.g regions untouched; Runtime/PermissionGate hunks textually
  disjoint from canvas-era lines.
- Deviations: (a) `Bash::DEFAULT_MAX_OUTPUT_BYTES` reference (trait constants not
  referenceable off-trait — same value, by reference to Bash); (b) env test uses `echo` not
  `cat` (cat would treat the value as a filename — test-side only); (c) loader emits literal
  Lang::t keys (composition hid them from the catalogue scan); (d) design cites stale →
  re-derived live (MCP_TOOL_PREFIX :848→:860 era moves, isWriteTool/evaluateAuto/unfilteredTools/
  mcpToolShape re-anchored); (e) 2 PHPUnit deprecations pre-existing suite-wide; (f) foreign
  reds reported-not-cured (PtyRelay ×2 arms, BootstrapLaunchFormat doc-leg, HelpTest
  SERVICE_TIER, suite-figure pair); (g) timeout pin sleep(20+10) bounded by 1s declaration;
  (h) suite-figure.json/durations.tsv untouched (battery mints; 3 new *Test.php files listed
  above for §7); (i) EnvRoster reverse arm widened to reads∪exported — guard-moves-with-
  surface, prominent disclosure; (j) cascade beyond the design file-list (same-commit law
  forced it): Chat.php, SETTINGS.md, RoutingTest, SeamCensus, StderrEmitterCensus,
  McpToolWiringTest, EnvReadScanner, EnvRosterDriftTest, en.php help screen entry,
  ENVIRONMENT.md — each is a roster/prose census my sanctioned seam call or new export feeds;
  (k) null passes the scalar gate (rendered empty string) — design says refuse array/object,
  silent on null; documented in-file.
