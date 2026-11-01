# C-TUI-6.l — Voice core: Transcriber, AudioRecorder, voice config (W39 LATE)

Lane /home/sites/cc-cmp-C-TUI-6.l, branch cc/C-TUI-6.l, base master 13f9a1829cfb30a2adc038e64ff70e0876210fe9 (verified identical, porcelain 0). PHP-only step (design files: candy-code src/docs/lang/tests only; zero web). Design read verbatim from prompt_kit/findings/comparison-1026/design-tui.md (C-TUI-6.l block, "Voice core" heading).

## Status: IN PROGRESS

## Plan of record
1. Config layer: MediaSettings voice.* definitions, LayeredSettings LAYERED rows, VoiceConfig, lang keys, MediaSettingsTest in-step flip, docs regen.
2. AudioRecorder + AudioRecording + guard rows (ReadPathCensus, DescriptorInheritanceGuard).
3. Transcriber + TranscriptionException.
4. VoiceSession.
5. TranscribeCmd + VoiceTranscribedMsg/VoiceFailedMsg.
6. tests/Media/Voice/*.
7. Gates.

## Commits
(none yet)

## Grounding (resume #1, 2026-10-31 ~20:05)
- Lane was at base 13f9a1829 with 2 dirty paths (MediaSettings.php edit + VoiceConfig.php), 0 commits, REPORT 875B — fragment class, nothing lost.
- Master moved to 85bfba37f (C-TOOL-6.h weld, 22 files, ZERO overlap with my paths) -> sanctioned zero-pick `git merge --ff-only` in-lane. Lane base now 85bfba37f.
- LayeredSettings rows + both pinned rosters (LayeredSettingsTest userTierOnlyKeys list, MediaSettingsTest ThirteenKeys + voice tier/default/range pins) landed IN-STEP.
- lang/en.php: 5 labels (after settings.vimMode.label run, before watchFiles), 5 helps (sorted tail region), 7 media.voice.* refusals in a new marked sorted run `--- media.voice (C-TUI-6.l: voice core refusals) ---`. Verified via python3 -I dupscan (416 keys, 0 dups) + per-region sortedness + neighbor anchors. NEVER grep -c.
- gen-settings-doc.php --write rewrote README (one hundred and forty-eight->fifty-three, eighty-nine->ninety-four project-never list, voice rows in layered roster) + docs/SETTINGS.md (every-key table rows, restart-arm membership, in-numbers 83->88) — all word-numbers DERIVED by the generator from my tree, none hand-typed.
- Gate: (MediaSettingsTest|LayeredSettingsTest|SettingsSchemaTest|SettingsSchemaDocDriftTest|LangParityTest):: -> Tests: 188, Assertions: 7637, Failures: 0, deprecations 2 (pre-existing same-2 as the 3F baseline run).

## Commits
- 61c449790 C-TUI-6.l: voice settings family + VoiceConfig — 8 files, +254/-12, %ae=1 %ce=1 detain, 0 Co-Authored (cat-file -t: commit)

## Slice 2 — 8f861e65741b35175b2c8fb0c76a8888ff83887f 'C-TUI-6.l: voice core - recorder, session, transcriber, cmd'
11 files: src/Media/Voice/{AudioRecording,AudioRecorder,TranscriptionException,Transcriber,VoiceSession,TranscribeCmd}.php, src/App/Voice{Transcribed,Failed}Msg.php (+ slice-1 VoiceConfig), guard rows ReadPathCensusTest x3 + DescriptorInheritanceGuardTest x1 (SAME COMMIT as sites), docs/MEDIA.md 'Voice input' section.
Master moved 85bfba37f -> 77beb227d (2 C-TOOL-6.h welds, zero path overlap with this step's files — verified git diff --name-only empty). Lane keeps base; welder re-picks.
Gate figures (lane tree, includes slice-2): ReadPathCensus+DescriptorInheritanceGuard+DocFigureProseDrift+EnvRosterDrift+ReadmeRosterDrift+ForkedChildExitConvention+ChildStderrCapture+StderrEmitterCensus = 279T/77,856A, 5F ALL FOREIGN-INHERITED (StderrEmitterCensus 2F src/Background/PtyRelay.php::main unclassified/roster — 6.j debt class; ChildStderrCapture 2F test dirs Chat/Backend/Automation/Server/Host exec-inherited — roster-known; ForkedChildExitConvention 1F PtyRelay::main:213). Zero failure messages name Media/Voice files. BARE TreeWideGuardRosterTest 17T/3957A OK; BARE DuplicatedTestHelperDriftTest 14T/204A OK. tools/check-one-type-per-file: 62 libs 3381 files 0 problems. php -l clean all.

## Slice 3 — 51cc0fdb0537f1c8a909bf8ac071743e1fa57372 'C-TUI-6.l: stat-cache honesty + key-redaction fixes + voice test suite'
8 files: src/Media/Voice/AudioRecorder.php + Transcriber.php (post-slice-2 fixes), tests/TreeWideGuardRosterTest.php (2 rows), tests/Media/Voice/{AudioRecorderTest,VoiceConfigTest,TranscriberTest,VoiceSessionTest,TranscribeCmdTest}.php.
PRODUCTION BUG FOUND+FIXED (stat cache): parent's filesize() in hasAudio() kept answering the reserveTake-time zero size while the recorder child wrote — under any long-lived parent (phpunit proved it) stop() could report the silent-empty LIE on a filled take. Cure: \clearstatcache(true, $wavPath) first line of hasAudio(), WHY docblock.
Transcriber fixes: (1) off()/noAudio() guards moved INSIDE the try so the finally-unlink covers every exit incl pre-dial refusals (matches MEDIA.md "deleted every path"); (2) excerpt($body, $key='') redacts the request key from endpoint error bodies after Sanitize::untrusted — closes the echo-the-credential leak vector; both status()/unparseable() call sites pass $expanded.
TEST FACTS: Guzzle Client::applyOptions (vendor/guzzlehttp/guzzle/src/Client.php:1152-1154) folds the 'multipart' option into a MultipartStream body and UNSETS the option before any handler runs — bare-handler tests inspect (string)$request->getBody() (MultipartStream renders CRLF; language leg asserted via /name="language".*\r\n\r\nde\r\n--/s). TranscribeCmdTest drives the REAL fork e2e (settleTick shape: AsyncCmd promise + Loop::get() 20s leash) — off-endpoint child writes refusal over ToolIpcFiles, parent settles VoiceFailedMsg naming 'voice input is off', latch released, take deleted by the child. No-pcntl sync arm + shutdown-hook registration = STRUCTURAL source pins only (host has pcntl; hook invisible in-process) — UNCONFIRMED-BY-RUN, honest.
TreeWideGuardRosterTest: +2 rows (Media/Voice/TranscriberTest.php + VoiceSessionTest.php, scandir($dir) prune walkers) SAME COMMIT as the test files that introduced the sites.
Gates: voice five-class battery 44T/208A/0F OK (12T/58A, 6T/45A, 12T/58A, 6T/19A, 8T/28A); zero markTestSkipped in the five files (no SuiteSkipRoster rows needed). Guard battery 311T/78,423A/6F ALL FOREIGN-ADJUDICATED: StderrEmitterCensus 2F (PtyRelay 9-site roster + thirty-eight-vs-47 doc-leg), ChildStderrCapture 2F (Chat/Backend/Automation/Server/Host exec-inherited), ForkedChildExitConvention 1F (PtyRelay::main:213), GlobDialectDifferential 1F — +1/+1 drift (560×556 vs docblock 559×555) proven BASE-CARRIED: my files admit ZERO glob-shaped literals (replica scan), the four blame-matched literals (LayeredSettingsTest :364/:557, MediaSettingsTest :337, DescriptorInheritanceGuardTest :1298/:2614) carry commits f05851491/a3a94d980/15776c8df/875f66d6c ALL merge-base ancestors of lane base 85bfba37f; PathGlob docblock re-derive is a weld/gate duty, report-only. BARE TreeWideGuardRosterTest 17T/3969A OK, BARE DuplicatedTestHelperDriftTest 14T/204A OK, SettingsSchemaDocDriftTest 19T/203A OK, one-type-per-file 62 libs/3381 files/0 problems. 'PHPUnit Deprecations: 2' pair is tree-wide pre-existing (rides every filter incl never-touched classes).
MUTATIONS EXEC (cp+md5 restore, mut/run.py): M1 clearstatcache leg → exactly 1F (stop-honesty pin); M2 redaction neuter → exactly 1F (testErrorAnswerCarriesTheStatusButNeverTheKey); M3 guards pulled out of try → exactly 2F (both pre-dial refusal+unlink pins). All restored md5-clean; commit carried post-mutation tree.
§7 NEW TEST FILES for durations minting: tests/Media/Voice/AudioRecorderTest.php 12T/58A; VoiceConfigTest.php 6T/45A; TranscriberTest.php 12T/58A; VoiceSessionTest.php 6T/19A; TranscribeCmdTest.php 8T/28A (total 44T/208A).
