# C-SES-8.g — JSONL wiring: setting, store mirror, `transcript_path` for hooks

VERDICT: CLEAN. Branch cc/C-SES-8.g, tip `4202b510e238e0287400644ddff8fe1caf8912d5` (one commit off base 740c29868; author+committer detain verified, no Co-Authored trailer; working tree clean).

## What was built (per design-ses.md:942 block)

- Setting `transcriptJsonl` (Bool, default false, Interface category, RiskClass::Tuning, layered, USER tier only — NO withProjectSettable; ApplyMode::Restart; reader symbol `Bootstrap::sessionStore`). src/Config/Settings/Definitions/InterfaceSettings.php.
- lang/en.php: `settings.transcriptJsonl.label` + `.help` inserted in the sorted label/help clusters.
- `Bootstrap::sessionStore()` (src/Cli/Bootstrap.php): new public const TRANSCRIPT_JSONL_SETTING; when UiSettings::bool is on → JsonlTranscriptWriter::setEnabled(true) + `$store->withJsonlMirror(new JsonlTranscriptWriter(projectRoot: LaunchContext::projectRootForSettings()))` before `return $store;`. No sprintf/emitter added (BootstrapLaunchFormatConstants/StderrEmitterCensus untouched by construction).
- EnhancedSessionStore (src/Session/EnhancedSessionStore.php): private ?JsonlTranscriptWriter $jsonlMirror; `withJsonlMirror(JsonlTranscriptWriter): self` (by-set returning $this — disclosed deviation below); saveTranscript transaction closure now returns bool (false on the tombstone skip, true after the real write); the mirror call `$wrote === true → mirrorTranscript()` sits AFTER writeTransaction returns; private mirrorTranscript() wraps `->save()` in try{}catch(\Throwable){} — never throws.
- ScriptHook::stdinPayload (src/Hooks/ScriptHook.php): seventh fixed key `transcript_path` — ALWAYS present; null while JsonlTranscriptWriter::enabled() is false; else JsonlTranscriptWriter::pathFor($context->sessionId, LaunchContext::projectRootForSettings()). Docblock six→seven keys with the present-null doctrine + the settings-root-vs-cwd reasoning.
- Docs: docs/HOOKS.md stdin payload paragraph now lists seven keys + transcript_path semantics (CRUSH_* env "eight/six" sentences untouched — env domain). docs/SETTINGS.md + README.md settings:layered block + src/Config/LayeredSettings.php regenerated with `php tools/gen-settings-doc.php --write` (ENVIRONMENT.md unchanged: no withEnvVar).

## Deviations (disclosed)

1. `withJsonlMirror` returns $this (by-set), not a clone: EnhancedSessionStore owns a live PDO handle + identity WeakMaps and the launch holds exactly one; design named the method, the service-object semantics decide the shape. Pinned by assertSame in the store test.
2. Design cited the store test as "tests/Host/TranscriptStoreTest.php" — that file does not exist; saveTranscript coverage lives in tests/Session/TranscriptResumeTest.php, mirror pins landed there instead.
3. `transcript_path` is derived from LaunchContext::projectRootForSettings() (the same static Bootstrap::sessionStore() builds the mirror writer against), NOT $context->projectRoot — entry points remember configRoot($root) while Chat/hook contexts carry the launch cwd; using the context root could name a file the store never writes. Pinned by a test where the two roots differ.
4. "throwing mirror doesn't fail save" cannot be exercised functionally (writer is final and never throws); pinned structurally (call inside try/catch(\Throwable), after the transaction call) per house source-slice precedent + docblock truth.
5. stdinPayload site drifted from the design's :1067 to :1291; implemented at the live location.

## Tests modified (no new test FILES — welder owes no durations rows)

- tests/Hooks/ScriptHookTest.php: six→seven key-order pin; new testTranscriptPathIsAPresentNullOffAndTheJournalPathOn (reflection on stdinPayload; off=present-null, on=journal path under pinned root with settings-root slug, non-creating; finally restores setEnabled(false)/pinDefaultRoot(null)/root).
- tests/Session/TranscriptResumeTest.php: three new tests + journalLines helper (mirror feeds committed save, 0600, append-only first-line byte-pin, tombstone skip adds nothing; rolled-back JsonException save leaves no journal + no row; structural commit/catch ordering pin).

## Gates (targeted, cwd=worktree/candy-code, -c phpunit.xml, plain pipe, at tip)

- tests/Session/TranscriptResumeTest.php — OK 14 tests / 64 assertions (11 base + 3 new).
- tests/Hooks/ScriptHookTest.php — OK 84 tests / 405 assertions (83 base + 1 new).
- Docs-guards filter `TrustKeyDocumentationDriftTest|DocFigureProseDriftTest|ReadmeRosterDriftTest|SettingsSchemaDocDriftTest|ConfigWriteProducerDocumentationDriftTest|SettingsSchemaTest|HookEnvironmentDocumentationDriftTest|EnvRosterDriftTest|CommandDocsDriftTest` — OK 238 tests / 48,306 assertions (2 PHPUnit deprecations pre-existing on base runs too).
- Adjacent filter `ScriptHook|JsonlTranscriptWriter|TranscriptResume|SessionSearchIndexTest` — OK 187 tests / 868 assertions.
- INHERITED RED (NOT mine, disclosed): `UsageRecordsTest::testEverySettleArmCarriesItsSourceLabelAtTheCallSite` fails at base 740c29868 too — SessionHost carries a third `usageRecordSink(), null, '` arm ('warm', KeepWarm weld) while the test roster still says 2. Verified `git diff 740c29868 HEAD -- candy-code/src/Host/SessionHost.php` empty; upstream commit c013d930d ("W29 wave-review: MAJOR-1 bare-sink count 2->3") already fixes it on master — not an ancestor of my cut, so it disappears on weld. No file I own.

## Mutation proofs (cp+md5 restored, all one-flip-discriminating)

- M1 neuter mirror call (`if ($wrote === true)` → `if (false)`): TranscriptResumeTest 1F — names testTheJsonlMirrorFeedsAfterACommittedSaveAndGrowsAppendOnly. Restored md5 ea044221…
- M2 tombstone skip returns true: same test red (its tombstone leg). Restored.
- M3 `transcript_path` forced null: testTranscriptPathIsAPresentNullOffAndTheJournalPathOn red (path leg). ScriptHook restored md5 9aa1962e…

## Notes for the welder/reviewer

- No new test FILES — no durations.tsv rows owed. suite-figure.json/parallel-tests-durations.tsv untouched (+~4 tests total; re-pin is closeout duty, not mine).
- README.md diff: generated layered block + the never-project-set sentence now names `transcriptJsonl` with its hook-trust rationale; the generator auto-renumbers the spelled count (eighty-four→eighty-five) — do not hand-bump it.
- ScriptHookJsonStdoutTest verified NOT to pin the stdin key list — untouched; grep `tool_response` tree-wide hits only the flipped pin, code and docblocks.
- `transcript_path` uses the SAME static (`LaunchContext::projectRootForSettings()`) on both the store side and the hook side, so a launch cannot hand a hook a path its store never writes; pinned by the differing-roots test leg.

- LayeredSettings.php + docs/SETTINGS.md are gen-settings-doc output; regenerate rather than hand-edit.
