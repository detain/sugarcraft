# REVIEW-1 — C-SEC-5.a (round 1) — static-only (shell denied: no gates, no git run here)

VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 1 MINOR / 2 NIT. Scope, census completeness, shared-door
semantics, memo-only pin reader, §1.8 in-step census moves, and the foreign-red disclosure all
verified sound against live tree. Gate figures (40T/635A, 119T/25731A, five-guard pair), single
commit, clean tree, and %ae/%ce author greps are builder claims UNVERIFIABLE in this sandbox —
weld must re-run.

- [MINOR step-local] src/Cli/Bootstrap.php:8104-8108 — MCP-door early returns (ABSENT/OUTSIDE_TREE/
  DISABLED) yield {granted:false, persisted:false, error:null}: a failed grant with no explanation,
  contradicting the method's own "show the user WHY" docblock. Fix: if !$report['granted'] &&
  null===$report['error'], set error => Lang::t('cli.mcp.trust.not_granted', ['root'=>$root,
  'config'=>self::userConfigPath()]) (row already exists, en.php:784).
- [NIT] src/Config/Trust/TrustTarget.php:16-18 — garbled sentence ("refuses to compile-check-free");
  reword to "every match below is exhaustive, so a new case cannot silently slip through a call site."
- [NIT] src/Cli/Bootstrap.php:8074 / :8193 — byte-identical memo-reader closures; extract one shared
  private helper.

Key confirmations: census covers every live trustedProject* surface (4 PermissionSettings lists +
pin-state sharing trustedProjectMcp — no 5th key, TrustKey roster unaffected); grantProjectTrust
reuses the exact pre-existing doors (trustProjectMcp / SettingsWriter::grantTrust as App.php:4014),
never-throw, no widening; McpDecision::mcpPinRefusals is a pure memo read (trustProjectMcp :363
untouched); Commands probe requires hasShellSubstitution per risk note; ReadPathCensus +
SettingsWriterCensus rows are mandated live-derivation flips landed in-step (§1.8); ChatHostLang-
CatalogueTest chat.effort.launch_unshaped red proven structural at base (key only in en.php, asked
only from LaunchContext.php:2109, absent from test sources()) — disclose, do not cure. 8T+9T test
counts eye-verified; DUR rows disclosed. No new emitters/timers; E646 n/a.

(Lead note: static reviewer, shell denied — text persisted verbatim @1371@. Ruling: take MINOR +
NIT-1 wording; NIT-2 shared-helper extraction recorded as carry (optional refactor, byte-identical
src-local closures, no drift guard accuses them).)
