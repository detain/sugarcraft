# REVIEW-1 — C-SEC-8.b (cc/C-SEC-8.b, worktree /home/sites/cc-cmp-C-SEC-8.b)

VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 1 MINOR (step-local) / 3 NIT

Lane shape proven on disk: exactly 2 commits atop 9fb981c5c — 688547446 (settings/lang/rosters half) + e94d13c59 (Runtime choke half); both `git cat-file` OK; 9fb981c5c is an ancestor; `git status --porcelain` empty at review start AND after all mutation probes (git checkout -- restores, verified). Author hygiene: %ae=2/2 detain, %ce=2/2 detain (separate greps), zero Co-Authored-By; diff touches no suite-figure.json, no durations.tsv, no dist.

Design authority read verbatim (prompt_kit/findings/comparison-1026/design-sec.md ### C-SEC-8.b): opt-in user-tier `redactToolOutput` bool default false; sketch placed the redactor in `Runtime::resultMessage()`; risks block: "The TUI and served `tool.finished` event payloads come from `ToolFinished`, not from the folded message, and may still persist the raw text. The lane must measure which one sessions.db and the EventLog store."; test bullet: "If the served event log stores ToolFinished rather than the folded message, add the same pin for tool.finished." Plan §4 row adds no ruling beyond this (grep redactToolOutput plan_comparison.md -> single serialized-mentions line; design ruling: none).

## Claim 1 — PERSIST-PATH ADJUDICATION: VERIFIED TRUE (landed-blob proof @e94d13c59)

- Choke placement: `$result = $this->redactResult($result);` at Runtime.php:3760 inside settle() — AFTER every note/utf8Safe/invisible-tag scrub and BEFORE `emit(ToolFinished::fromResult(...))` at :3766; identical choke at :4545 before the failure() emit at :4547 (refusals can quote credential-carrying output: classifier echoes, hook reasons).
- Exhaustiveness: `ToolFinished::fromResult` occurs in candy-code/src ONLY at Runtime.php:3766 and :4547 (plus one TranscriptProjector docblock mention) — no third producer of the persisted event; `redactResult` has exactly the two call sites, both pre-emit. One redacted object therefore covers all three surfaces: serve EventLog (TurnRunner::record appends the durable tool.finished whose content folds from the row), transcript row (TranscriptProjector::finish reads $event->result; toolFinishedEvent derives persisted 'content' from that row), and the model replay (resultMessage at :3781, built from the same redacted $result).
- NON-VACUITY, measured on this branch with restored mutations:
  - m1 (drop failure() choke): `testFailurePathIsRedactedBeforeItEmits` — FAILURES! Tests: 24, Assertions: 43, Failures: 1. Builder's claimed 1-red discrimination CONFIRMED.
  - m2b (redaction ONLY at the model-fold, i.e. the design's original resultMessage placement — pre-emit choke removed, redact inserted immediately before return): FAILURES! Tests: 24, Failures: 2 — `testSettledToolOutputIsRedactedInBothEventAndHistoryWhenOn` and `testImageDocumentAndUsagePayloadsRideThroughUntouched`, both "Failed asserting that 'fetched: Authorization: Bearer supersecretvalue0123456789abcdef' [ASCII](length: 63) does not contain \"supersecretvalue0123456789abcdef\"". The pins would have caught exactly the raw-persistence regression the risks block warned about: had the lane taken the design sketch's placement literally, sessions.db/EventLog stored raw and no test in this file would exist to notice. The upstream-choke deviation is the correct adjudication of the design's own "lane must measure" instruction; the durable-surface test's comment records the measurement and the flip-condition.
- /export: design promises raw. serializeRaw() reads $this->messages through Exporter with NO redactor pass (it is serialize()'s SEC-4 redaction minus that pass), and the lane's diff does not touch ExportCommand.php at all (empty diff). The landed en.php help states the honest combined semantics: "`/export` adds no redaction of its own: it prints exactly what was stored, so anything saved before turning this on stays raw." TRUE and precise. BUT the PermissionSettings.php:147 comment claims "/export stays raw on either setting" — FALSE for post-toggle turns when ON (what was stored is already redacted; printing "exactly what was stored" is not raw). See MINOR-1.

## Claim 2 — EngineBackend +51 = pure plumbing: VERIFIED TRUE

- Content: REDACT_TOOL_OUTPUT_CONFIG_KEY const, private static redactToolOutput(?array $config) reader (lowercase/trim string normalisation; `match { true, 1, 'true', '1', 'yes', 'on' => true, default => false }` — fail-toward-OFF polarity, correct for a default-false security switch whose ON direction widens protection; string spellings accepted for hand-editable JSON / ${VAR} values), and threading at EXACTLY the 4 config-reading newRuntime() sites (:1882, :2372, :5065, :5084 — verified by blob census of `newRuntime(` call sites); newRuntime gains a named `bool $redactToolOutput = false` param forwarded as Runtime ctor arg. ZERO decision/redaction logic (no SecretRedactor call) in EngineBackend.
- The other 6 newRuntime() callers (promptSectionSizes, dumpSystemPrompt, prime* x2, primeSymbolMap, primeMemoryRecall) keep the false default — all are prompt-assembly/prime helpers with no tool settle/emit path, so no missed redaction channel.
- Ctor-param vs design's "with* on construction": design's parenthetical suggested a with*; the implementation mirrors the maxOutputTokens precedent (named param threaded through newRuntime). Sound — a with*-clone surface for a per-turn reader is the wrong shape; precedent decides. Not a finding.
- NIT-2: the definition's withReaderSymbol names `EngineBackend::newRuntime` while the actual reader is the private static `redactToolOutput()`; the symbol resolves and newRuntime is the public funnel, so TrustKey semantics hold — naming precision only.

## Claim 3 — throwing-listener residual: NIT (not a finding-grade gap)

The `[ToolFinished listener failed: %s: %s]` annotation is appended AFTER redaction and rides the model-facing message un-redacted. The persisted event and TUI/history fold already happened from the redacted object at emit time; the listener only ever receives the redacted ToolFinished, so raw tool output cannot reach a listener (nor its exception message) via this path — the annotation is UI-generated bytes. Design's threat model (secrets in tool output reaching history/replay) is closed. Recorded as NIT-1 with the disclosure the builder already made.

## Claim 4 — tests: VERIFIED, all green, quoted

cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe, --no-coverage, PHP 10.5.66 / PHP 8.3.6, at tree tip (restored, clean):
- ToolOutputRedactionTest: `OK (24 tests, 47 assertions)` — claim exact.
- tests/Runtime: `OK (98 tests, 370 assertions)` — claim exact.
- --filter EngineBackend: `Tests: 300, Assertions: 2066, PHPUnit Deprecations: 2` (deprecation pair = house AccessorDeprecationTest baseline) — claim exact.
- --filter TrustKey|DocFigure|ReadmeRoster|EnvRoster|StderrEmitterCensus|ConfigWriteProducer: `Tests: 268, Assertions: 68011, PHPUnit Deprecations: 2` — builder claimed 261T/67976A; measured HIGHER on this branch (provider-row growth between their measurement point and the landed tip — informational only, zero failures).
- tests/Config/Settings/SettingsSchemaDocDriftTest.php + tests/LangParityTest.php: `OK (26 tests, 3485 assertions)` — SettingsSchemaDocDriftTest IS the phpunit form of `gen-settings-doc --check`; direct `php tools/gen-settings-doc.php --check` was DENIED by this reviewer's permission map, so the guard-form proof stands (see Claim 5).
- Vacuity spot-check on the reader: m3 (drop 'yes' from the match arm) -> exactly 1 red: `testTheReaderHonoursOnlyValuesThatSaysSo with data set "string yes padded"` — "Failed asserting that false is identical to true." CONFIRMED. The 16-row provider is genuinely shape-covering (bare default, literal/int/string on-forms, TRUE case, padded 'Yes', off-forms, 'maybe', null, array, '').
- Off-path byte-identity pinned: `testTheOffSwitchHandsBackTheVerySameResultObject` — assertSame on the OFF path AND on an armed choke over clean bytes ("off must not even rebuild the object"); idempotence pinned: `testRedactingAlreadyRedactedBytesChangesNothing`.

## Claim 5 — SETTINGS.md authored "three→four" outside generated regions: VERIFIED

`git diff 9fb981c5c..e94d13c59 -- candy-code/docs/SETTINGS.md` shows the hand-edited tally lines sit in prose outside the `<!-- BEGIN/END generated -->` regions; the drift guard (phpunit form of gen-settings-doc --check) is green ON THE BRANCH — a regen-mangles-the-hand-text scenario would redden it. Regen-verify-RESTORE claim consistent.

## Claim 6 — commit hygiene: VERIFIED (see header).

## Findings

- MINOR-1 (step-local): `candy-code/src/Config/Settings/Definitions/PermissionSettings.php:146` — docblock comment claims "`/export` stays raw on either setting". With the flag ON, results are redacted BEFORE persistence (the choke this lane deliberately placed), so exports of post-toggle turns contain `[REDACTED]`, not raw. The sibling user-facing sentence (en.php settings.redactToolOutput.help) already has the truthful form. FIX: replace the docblock clause with "export adds no redactor of its own — it prints exactly what was stored (raw only for what was stored while the flag was off), and redaction never rewrites persisted history."
- NIT-1: throwing-listener annotation rides model-facing message un-redacted (Runtime.php:3768-3772) — accepted, reasoned above.
- NIT-2: withReaderSymbol names newRuntime instead of the private reader — accepted, public funnel.
- NIT-3: guard-set figure 261T claimed vs 268T measured on landed tip — informational.

## Weld-carry state

Pick range `9fb981c5c..e94d13c59` (EXCL-start = exactly 2 commits: 688547446, e94d13c59). Cherry-pick only — never tree-copy. 688547446 regenerates LayeredSettings/user-tier rosters + SETTINGS.md: expect content-resolve against concurrent master moves (SETTINGS.md hand-region + docs/MCP.md collision law: hand edits stay outside generated markers). DUR MINT: `candy-code/tests/Runtime/ToolOutputRedactionTest.php` 24 cases — use JUNIT-TESTCASE-SUM (bootstrap-polluted wall-clock lies). §7 carry +24T. Collect builder REPORT.md (main tree) + this REVIEW-1.md onto the branch pre-prune. MINOR-1 is one-line prose — fix-now at weld rider or lane follow-up; do not re-loop.
