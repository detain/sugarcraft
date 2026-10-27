# C-SEC-5.a — project-trust census + grant surface

Commit: 59d84834dbdb9b94ac26db8a0a32873517415b1e on cc/C-SEC-5.a (base 86f43fae2), 9 files, +766.
Author/committer both detain@interserver.net (separate greps =1), zero Co-Authored trailers.

## Landed
- NEW src/Config/Trust/TrustTarget.php — enum mcp/mcp-pin/hooks/commands/settings; configKey()
  (pin shares trustedProjectMcp — no 5th key, TrustKey census unaffected), askKey(), grantsThroughMcpDoor().
- NEW src/Config/Trust/ProjectTrustCensus.php — pure pending(declares, isTrusted) in case order.
- Bootstrap (beside trustProjectMcp): pendingProjectTrust(?root), mcpPinRefusals(?root) [memo-only
  via McpDecision::mcpPinRefusals + LaunchContext::mcpClientFor — never builds], grantProjectTrust(target,
  root, ?writer) [mcp door = trustProjectMcp (list+pins together); others = SettingsWriter::grantTrust;
  never throws → {granted,persisted,error}]. 3 private probes; Commands declares ONLY on a file with a
  !`form`. Brief's :7773 stale — real anchor was :8007.
- McpDecision::mcpPinRefusals public reader; lang/en.php 4 server.trust.ask.* rows.
- §1.8 in-step: ReadPathCensusTest +1 CONTAINED_UPSTREAM:Cli/McpDecision.php row (initial CONTAINED
  claim correctly reddened the measured arm — Bootstrap holds no routed enforcing call);
  SettingsWriterCensusTest +1 'Cli/Bootstrap.php::grantProjectTrust->grantTrust' roster row.

## Gates (plain-pipe, cwd worktree candy-code)
- 'ProjectTrust|BootstrapProjectTrust|McpDecision|TrustKey': OK 40T/635A (2 deprecations pre-existing
  in TrustKey window; my files alone 17T/54A clean).
- 'StderrEmitterCensus|SettingsWriterCensus|ReadPathCensus': OK 119T/25731A after in-step rows.
- five-guard: only the expected staleness pair red (README 25537 vs live 26112 — gate re-pin).
- php -l all 9 files clean.

## DUR (new test files for gate durations TSV)
- candy-code/tests/Config/Trust/ProjectTrustCensusTest.php (8T)
- candy-code/tests/Cli/BootstrapProjectTrustGrantTest.php (9T)

## Seam notes
- BASE RED, foreign: ChatHostLangCatalogueTest::testEveryCatalogueKeyIsAskedForByChatOrHost — key
  chat.effort.launch_unshaped asked only from src/Cli/LaunchContext.php:2109, which the guard's
  sources() list omits. Nothing in my diff touches it; disclosed, not fixed.
- Frozen-list semantics pinned on purpose: grant → disk yes, same-process census still pending
  ("effective next launch" — the grant-may-not-gate-itself property).
- TrustAsks surface NOT widened here (that's 5.b); pendingProjectTrust/mcpPinRefusals are its entry API.
- Not staged: suite-figure.json, durations.tsv, dist, generated protocols. Never pushed.

## Round 2 (fixer — lead ruling: MINOR + NIT-1 only; NIT-2 skipped as recorded carry)

Commit: 86cc08349 "C-SEC-5.a: review fixes (round 2)" (3 files, +36/-3; author/committer detain@interserver.net verified via separate %ae/%ce greps; zero Co-Authored trailers).

- [MINOR] Bootstrap::grantProjectTrust MCP-door early returns (ABSENT/OUTSIDE_TREE/DISABLED from
  McpDecision::trustProjectMcp) carried error=null, contradicting the "show the user WHY" docblock.
  Fix: src/Cli/Bootstrap.php ~:8104 — after $report, if !$report['granted'] && null === $error, set
  $error = Lang::t('cli.mcp.trust.not_granted', ['root' => $root, 'config' => self::userConfigPath()])
  (existing en.php:784 row, params root/config verbatim; no new keys). Pin:
  BootstrapProjectTrustGrantTest::testAnAbsentConfigStillExplainsTheRefusalThroughTheDoor —
  red-first proven (pre-fix: 10T/1F "null is of type string"), post-fix green.
- [NIT-1] src/Config/Trust/TrustTarget.php:16-18 docblock reworded: "every `match` below is
  exhaustive, so a new case cannot silently slip through a call site." (garbled phrase removed).
- Gates (plain pipe, cwd candy-code, env -u LINES -u COLUMNS): ProjectTrust|BootstrapProjectTrustGrant
  22T/80A OK (2 deprecations stash-proven pre-existing at base); TrustKey|DocFigure 109T/4499A OK;
  five-guard → Failures: 2 = exactly the ReadmeSuiteFigureDrift staleness pair (25537 pinned vs
  26113 live; foreign-era inherited + this leg's +1T, welder re-pins). php -l both src files OK.
- [NIT-2] shared memo-helper at Bootstrap.php:8074/:8193 NOT extracted (skipped per lead ruling).
