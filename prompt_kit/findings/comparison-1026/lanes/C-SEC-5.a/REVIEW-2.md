# REVIEW-2 — C-SEC-5.a (round 2) — fix commit 86cc08349 only — shell ALLOWED, gates RUN

VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 MINOR / 0 NIT (step-local).

1. Scope: git show --stat = exactly 3 files (Bootstrap.php +15, TrustTarget.php +2/-2 docblock,
BootstrapProjectTrustGrantTest.php +20 new leg). Worktree clean; no suite-figure/durations/dist/
generated surfaces in the commit. No extras.
2. Door arms: blanket fill `if (!$report['granted'] && null === $error)` at :8104-8115 uses the
EXISTING key cli.mcp.trust.not_granted (en.php:784, params root/config — byte-same shape as the
pre-existing consumer Subcommands.php:2485; no new keys, Lang import :44 resolves). Absent-config
(ABSENT/OUTSIDE_TREE/DISABLED, McpDecision:383) now carries the row; unreadable shapes keep their
populated passthroughs (:388/:392 inner rows, :8101/:8127 catch messages). Covers every error-null
shape incl. a non-throwing writeUserConfig miss. granted/persisted expressions byte-untouched;
App.php:3993 consumer is the separate settings-editor path — no production behavior change beyond
the error payload. TrustTarget reword = r1's exact prescription.
3. Pin: testAnAbsentConfigStillExplainsTheRefusalThroughTheDoor calls the REAL door with the
sandboxed ABSENT root; red-first claim statically consistent (assertIsString(null) -> "null is of
type string"); asserts error contains $root AND userConfigPath(). McpPin granted-leg assertNull
still green -> fill does not misfire on successes.
4. Gates (plain pipe, cwd worktree candy-code, shell DENIED env -u form; tty noise absent anyway):
--filter 'ProjectTrust|BootstrapProjectTrustGrant' = OK Tests: 22, Assertions: 80, 2 pre-existing
deprecations (REPORT EXACT); 'TrustKey|DocFigure' = OK 109T/4499A (REPORT EXACT — fixer claim
confirmed); five-guard = 133T, Failures: 2 = exactly the ReadmeSuiteFigureDrift staleness pair
(25537 pinned vs 26113 live = build-era + this leg's +1T; welder re-pin duty, not step-local).
StderrEmitter/SettingsWriter/ReadPath rosters unflipped by delta (no new emitter/write/read site).
5. Author law: %ae=CE grep separate, both detain@interserver.net; body = subject only, zero
Co-Authored trailers. Round-2 section present in REPORT.md (:40-57), NIT-2 skip recorded per lead.
