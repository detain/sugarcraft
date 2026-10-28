# C-AGT-7.b — honest session_id and json_schema envelopes in -p mode

Commit `2ac6dfd022c43019ce7ef23c6f51c7270a648ed0` on `cc/C-AGT-7.b` (base `ebf05989e`, one commit, never pushed, author/committer detain verified by separate greps, zero Co-Authored trailers).

## Files (7)
- `candy-code/src/Cli/ArgvParser.php` — `--json-schema` without `-p`/`run` → usage error exit 2 (`cli.argv.json_schema.requires_prompt` + hint). DECISION on the sibling: `--output-format` gets NO guard — Serve/SelfUpdate/bin usage paths read it promptless and doing real work; asymmetry documented in the guard's comment.
- `candy-code/src/Cli/NonInteractive.php` — json/stream-json persist BEFORE render via a `$persistSession` closure with one success path; `session_id` only when the save came through; text order byte-unchanged. ToolFinished observed in `$onEvent` → `$schemaUnapplied` → one stderr notice (all formats) + `json_schema_applied:false` as format()'s 7th conditional key (append-default param; absent, never true). `Runtime` untouched. Docblock SIX→SEVEN conditional.
- `candy-code/lang/en.php` — 3 keys (only locale file; parity guard trivially green).
- `candy-code/README.md` — exit-2 enumeration gained the condition; "Seven keys are conditional"; save-refused key rule; new first-request-boundary paragraph; session_id wording.
- `tests/Cli/LaunchFlagsTest.php` — guard test + 3 prompt spellings + 4 promptless `--output-format` asymmetry rows; 4 schema-spelling rows gained their `-p` (in-step flip of the old-world pins).
- `tests/Cli/JsonSchemaEnvelopeTest.php` — format-layer key discipline (position false-only last; text arm inert) + child-process e2e: real EngineBackend whose provider asks for an unknown tool (signal without execution), stdout+stderr pinned together, tools/clean polarity pair.
- `tests/Cli/NonInteractiveSessionPersistenceTest.php` — save-failure discriminator (WAL db chmod 0444 after mint: open succeeds, first write raises — the only state separating announced-but-unwritten from the cannot-open downgrade) + structural order pin.

## Tests / regression evidence
All five new/extended assertions re-verified RED against base source by file-copy swap of the three modified non-test files (md5-restored after). Post-restore gates plain-pipe cwd candy-code:
- `--filter 'NonInteractive|LaunchFlags|JsonSchema'`: Tests: 352, Assertions: 1721 — OK (2 pre-existing deprecations).
- `--filter 'ReadmeJson|DenialPrefixRoster|LangParity'`: Tests: 43, Assertions: 3436 — OK.
- five-guard `ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift`: 133T/15759A, only the expected suite-figure staleness pair red (figure/durations are lane-forbidden; gate re-pins).
- adjacent `StreamJsonEmitter|EngineBackendJsonSchema|SubcommandsJson|ArgvParserTest`: 157T/528A — OK.
- `php -l` clean on every touched PHP file.
- New test METHODS only in existing files → no new `*Test.php` → DUR: none (gate still owes the suite-figure pair).

## Notes
- Persistence detail discovered by measurement: on a read-only WAL db the transcript rides the unmerged `-wal`, so the post-failure proof reads the row's `turns` column (the in-run store still sees both messages); the refused write itself is pinned structurally.
- `tests/Config/` carries no StderrEmitterCensus file in this tree — the two new `fwrite(STDERR)` sites (notice + moved save-failure line) needed no roster.
- The child e2e reuses the house proc_open-array + timeout + terminate/close discipline; `tools/check-child-lifetimes.php` walks autoload roots only (tests/ exempt).
- stream-json save-before-render is pinned by the shared `$persistsFirst` expression + structural arm; its functional twin awaits a STDERR-seam (`$streamOutForTesting` covers STDOUT only) — noted, not widened.

## Round-2 fixer (2026-10-27)
All four REVIEW-1 findings taken; one commit on cc/C-AGT-7.b (author-verified, never pushed).
- MAJOR: NonInteractive.php — `$schemaUnapplied` now ORs `historyCarriesUnansweredToolResult($history)`: scans the Chat-side Message DTO rows (last toolResults row >= last toolCalls row, result row must exist), mirroring Runtime::jsonSchemaFor's withhold position over a resumed transcript; decision-block comment truth-flipped; engine untouched.
- MINOR: JsonSchemaEnvelopeTest.php — new e2e `testAResumedSchemaRunOverAToolEndedTranscriptSaysFalseOnBothChannels`: seeds a tool-ended (and a tool-less control) transcript via the production `EnhancedSessionStore::saveTranscript` (auto-creates the session row — a bare TranscriptStore save never resolves as a resume target), resumes it under a real sglang provider against a route-aware loopback; carried leg pins key=false + exactly ONE stderr notice, clean leg pins key ABSENT + silence (over-application guard). Seeding uses the root `SugarCraft\Code\ToolCall/ToolResult` DTO pair — the `Tools\` engine-side classes do not round-trip the transcript serializer (fromArray demands the persisted `name` keys the DTO pair emits).
- NITs: README 'Three of the seven' → 'Three of those seven error types'; the duplicated `$explicitFalse` leg folded into the `$default` assert.
- Gates (cwd candy-code, plain pipe): `NonInteractive|JsonSchema|SessionId|Headless` 374T/1652A green (373 + the new method); `ReadmeJson|DenialPrefixRoster|LangParity|TuiLang` 52T/4820A green; five-guard 133T — ONLY the staleness pair red (pinned 25537 vs live 26203 = +1 new method; re-pin is the weld's duty, consistent with review R-6).
- Mutation: reverted the history-carry OR-leg → resume pin reddens exactly on the carried key-presence assert, clean leg stays green; cp-restore, md5 312aa36c... verified.
