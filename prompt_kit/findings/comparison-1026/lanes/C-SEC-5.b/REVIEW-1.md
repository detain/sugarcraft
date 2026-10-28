# C-SEC-5.b — REVIEW-1 (round 1)

**VERDICT: FINDINGS — 0 CRITICAL / 1 MAJOR / 0 step-local MINOR**

Worktree `/home/sites/cc-cmp-C-SEC-5.b`, branch `cc/C-SEC-5.b`, ONE commit `563b1c0c5` atop master `b985fbe51`. `git status` clean. Author/committer `detain@interserver.net` (`%ae`/`%ce` verified separately, 1 detain each), zero Co-Authored trailers.

## MAJOR-1 — DuplicatedTestHelperDriftTest RED at the lane tip (2F)
`(cd candy-code && env -u LINES -u COLUMNS vendor/bin/phpunit -c phpunit.xml --filter DuplicatedTestHelperDrift)` → `Tests: 14, Assertions: 190, Failures: 2` (green at base — the new test file's helper pairs introduced it; the builder's gate list omitted the five-guard family):
1. `testNoCopiedHelperHasDriftedInItsSignatureAlone` — `sources` and `trustRow` exist in BOTH `tests/Protocol/ContextTrustPromptTest.php` and `tests/Protocol/ContextTrustTargetsTest.php` with identical bodies but signature-only drift (FQN `\SugarCraft\Code\Tests\Server\Support\WireClient` vs imported short `WireClient`; `trustRow` default `= 'mcp'` only in the PromptTest copy).
2. `testNoCopiedTestHelperHasDriftedUnrecorded` — `declareMcpServers` in both files, byte-identical except the server-name literal (`local` vs `ledger`).
Fix (house precedent, lane-ce): rename the TargetsTest helpers (`declareLedgerServer`, distinct `trustRow` name) and byte-harmonize the remaining pair (import WireClient in both; drop or share the default). Re-run the guard at the tip.

## Verified clean (reproduced)
- Gates: `--filter ContextTrust` → 15T/114A OK (2 pre-existing deprecations); `ContextSourcesTest|GatewayTrust` → 11T/140A OK; vitest 4 specs `--no-file-parallelism` → 29/29. GlobDialect green.
- Protocol-lane procedure: commit diff for the trio (`candy-code.v1.schema.json`, `SERVER.md`, `generated.ts`) EMPTY; `git status` clean; `generated.ts` still carries the old `trust?:` (correct pre-weld state). `ProtocolSchemaDriftTest` red on the restored tree ONLY, and its derive-vs-commit diff shows the derived artifact carrying exactly the lane's five-case enum + `trusts` descriptions — source/enum edits consistent; drift is weld regen duty. `ServerProtocolDocumentationDriftTest` and `TrustKeyDocumentationDriftTest` green here. Builder's 854A-vs-my 853A on that filter = expected restored-tree leg difference.
- Security posture: `TrustAsks::settle` routes user grants through `Bootstrap::grantProjectTrust(TrustTarget, root, ?SettingsWriter)` — the SAME door C-SEC-5.a built and `App::grantProjectTrust` (TUI) / `trustProjectMcp` (CLI) ride: mcp/mcp-pin → MCP door (list + fingerprint re-record atomically, `grantsThroughMcpDoor()`); hooks/commands/settings → workspace `SettingsWriter::grantTrust` (instanceof-narrowed `service(SettingsWriter::class)` in `ContextMethods::trust`, LaunchContext fallback), which validates the key against the Frozen StringList `trustKeys()` roster and `assertLanded()` throws on a silent write failure → surfaces as `trust_write_failed`, record stays `asked`. Project-tier remember refused; root-mismatch misroute refused (`ask_not_found`); `persisted` derived from the real write (`$report['persisted'] === true`) — no fabricated success. ContextSources answer restraint (URLs off the wire) untouched; reasons built via `Lang::t($target->askKey())`. No new spawn, no SSRF surface, no timeout added (E646 moot).
- Deviations: 5 out-of-brief test/spec files are rename/shape adaptations (`trust`→`trusts`, `record`→`records`, single shelf→list, `respondTrust(askId,…)`); the retired single-prompt refuse-over-open law is REPLACED by a merge-by-askId no-resurrect pin in `contextTrustRouting.spec.ts` — adaptation, not waiver. `discover`/`record`/`TARGET_MCP` have zero stale callers tree-wide.
- Lang claim disk-verified: all five `server.trust.ask.*` + `server.trust.write_failed` exist in `candy-code/lang/en.php` at master (5.a commits `b20852ec8`/`455025890`); lang dir is en-only.
- `dist/`, `suite-figure.json`, `scripts/parallel-tests-durations.tsv` untouched by the commit (diff --stat empty). Durations row for `ContextTrustTargetsTest.php` disclosed in REPORT (gate duty).
- Injection incident: REPORT.md §6 discloses ~13 injected REWRITE TASK demands, refused. Commit content matches the brief only; no push occurred; no config values or concealment; all reported gates reproduced independently.
- Scope: 18 files, all in the brief's create/modify lists plus the disclosed adaptations; hub edits (Definitions/MethodSchemas) inside declared regions.

## Weld-cure dispositionable (notes, not findings)
- Weld regenerates the trio (§1.8/§2) and mints the durations row; staleness-pair and standing base reds (StderrEmitterCensus, TreeWideGuardRoster, ProjectTierRefusalInventory) not attributed to the lane.
