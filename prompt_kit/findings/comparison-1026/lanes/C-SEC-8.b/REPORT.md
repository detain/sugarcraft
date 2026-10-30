# C-SEC-8.b — opt-in redactToolOutput over settled tool output

VERDICT: COMPLETE on branch cc/C-SEC-8.b @ /home/sites/cc-cmp-C-SEC-8.b.
Tip e94d13c59 (cat-file'd) atop inherited 688547446 (cat-file'd). Base 9fb981c5c; weld handles master drift.

## Commits
- 688547446 (inherited): PermissionSettings definition, LayeredSettings LAYERED_KEYS row, lang/en.php keys, README + SETTINGS.md regenerated rosters.
- e94d13c59 (this lane): Runtime reader+choke, EngineBackend plumbing, ToolOutputRedactionTest, en.php help truth-fix, SETTINGS.md authored prose fix. 5 files +573/−4. Test file +454 (prior builders' 420 + this lane's +34-line serve-surface pin and docblock edits).

## Persist-path measurement (design's flagged question) — RAW persisted; choke moved
sessions.db/EventLog do NOT store the folded ToolResultMessage. Evidence read live:
- TranscriptProjector::finish() folds the transcript row from $event->result (ToolResult::fromEngineResult, src/Host/TranscriptProjector.php:172+);
- toolFinishedEvent() derives the persisted 'content' from that row ($result->result, :277+);
- TurnRunner::record() appends durable SessionEvents via EventLog->append() (src/Host/TurnRunner.php:1146+, isDurable arm).
Consequence: the design's "redact inside resultMessage()" would leave every persisted surface raw. redactResult() runs ONCE in Runtime immediately BEFORE the ToolFinished emit at BOTH settle() and failure(); the folded message is built from the same redacted object, so event, TUI row, sessions.db, and model-replayed history all carry it in one pass. Design's "add the same pin for tool.finished" satisfied behaviorally (e2e tests assert the captured event) AND structurally (new test folds through TranscriptProjector + asserts the durable wire payload content).

## EngineBackend +51 adjudication — KEEP, not widening
No redaction logic in EngineBackend (zero SecretRedactor refs there). The diff is the settings plumbing the design names: REDACT key const, private affirmative-only reader (16-shape provider-pinned), newRuntime() parameter threaded at the class's four per-turn config sites — the maxOutputTokens (E707) precedent. Deviation from the design's parenthetical "a with* on construction": named ctor param instead (with* onto a fresh object is dead weight; same "on construction" semantics). Definition commit already declared withReaderSymbol(EngineBackend::newRuntime).

## Disclosed residual
A throwing ToolFinished listener's annotation is appended AFTER the choke in settle(); it rides model-facing only (persisted event already emitted pre-annotation); listener UI text, not tool output. Noted in commit body.

## Gates (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)
- tests/Runtime/ToolOutputRedactionTest.php: 24T/47A green
- tests/Runtime dir: 98T/370A green (post-projector-pin, measured on the committed tree; pre-addition run was 97T/365A)
- --filter EngineBackend: 300T/2066A green (2 pre-existing deprecations)
- TrustKey|DocFigureProse|ReadmeRoster|EnvRoster|StderrEmitterCensus|ConfigWriteProducer guards: 261T/67,976A green
- tools/gen-settings-doc.php --check: rc0 pre- and post-commit
- php -l: all 4 touched PHP files clean
- SETTINGS.md diff is authored prose OUTSIDE the generated regions (settings:apply:end at :1536 precedes it); committed as authored.

## Mutations (cp backup /tmp/opencode/cc-cmp-C-SEC-8b, md5-verified restores)
- M3 emit-before-redact reorder -> exactly 2 event-surface reds (history pins stay green: surfaces proven independent)
- M4 failure-choke drop -> exactly 1 red
- M1 reader yes-drop -> exactly 1 provider-row red

## Author law
%ae=detain@interserver.net, %ce=detain@interserver.net on both commits (separate greps), zero Co-Authored-By. Worktree clean. Never pushed.
