# C-TUI-6.n — Web MediaRecorder + audio.transcribe + the canTranscribe flag

STATUS: COMPLETE — 3 picks on cc/C-TUI-6.n, all gates green on the committed+restored tree, porcelain clean, trio surfaces restored for the welder.

## Grounding
- Lane /home/sites/cc-cmp-C-TUI-6.n, branch cc/C-TUI-6.n, base 31000e3be (fresh cut verified pre-first-edit).
- Master moved mid-lane to e8dad69cd (6.g weld) — read-only `git rev-parse` proof in-citing-shell. NO rebase (lane law: picks stand, welder carries). Branch is NOT fast-forwardable onto master; welder merges/replays as usual.
- Companion marks read by text before coding: 6.l (✅ 417b5fe13,d3f2087e9,4da8acee4 voice core — Transcriber/VoiceConfig/AudioRecording, zero-diff law honored: called, never edited) and 6.m (✅ 401a07c9b push-to-talk, TranscribeCmd untouched).

## Picks (all cat-file'd in the citing shell)
1. `fb021a61a` — serve audio.transcribe + audio.status and the transcribe affordance flag (6 files +855/−12).
2. `96004109a` — web MediaRecorder dictation with server-engine preference (11 files +1075/−21).
3. `158b1c9a9` — document the audio pair in the media surface docs (MEDIA.md +17/−3).
Author/committer detain counts 1/1 per pick; zero Co-Authored trailers; explicit-path staging with `git add -N` for the three new files first.

## Design conformance
- audio.transcribe: Write scope, sideEffects=true (idempotency replay answers the same jobId), {audioBase64, mime, language?}; decoded ≤8 MiB checked BEFORE any write; mime allowlist {audio/webm,audio/ogg,audio/mp4}; 0600 take via umask-0077 fopen('xb') @chmod MediaStore::FILE_MODE beside the render ledgers under `audio-` name space; forks the C-TUI-6.l Transcriber through the exact generate.submit TurnController::forkPayload shape; answers {jobId}.
- audio.status: Read; projects exactly {state, text?, error?}.
- Voice-off law: BOTH doors answer Forbidden kind `voice_unconfigured`; no dial, no file, no ledger (voice gate precedes everything but draining).
- Flag: MediaAffordance::flags() gains `transcribe` => VoiceConfig::fromSettings($settings)->enabled() — predicate is voice.baseUrl ONLY per the design block (NOT the key; brief's "+ key" wording superseded by design text, which is the authority). Key ORDER image,video,transcribe,baseUrlHostOnly; boot-memo/current()/flags() docblock shapes widened to the quad. Additive-hunk preserved (cc/C-TOOL-6.g shared-region law) and already committed.
- Schema: features.media gains OPTIONAL `transcribe` boolean (required list unchanged — old clients/servers interoperate); audio.* rows byte-consistent with the handler: schema maxLength IS TRANSCRIBE_MAX_BASE64_BYTES, mime enum IS allowlist keys, state enum IS AUDIO_STATES (5.d roster discipline, pinned by test).
- Web: NEW lib/recorder.ts ladder webm;codecs=opus → webm → ogg;codecs=opus → ogg → mp4 with the voice.maxSeconds cap (clamped 1..600s, auto-stop hands the take to the SAME path as the manual stop); connection.ts canTranscribe (`capabilitiesMedia.value?.transcribe === true` — absent key ⇒ false, an old server says nothing); dictation.ts pickEngine({canTranscribe,recorderReady,browserSpeech}): server when canTranscribe&&recorderReady, else browser when browserSpeech, else none; Composer.vue mic routes engine server-first, text inserted at caret via the existing insertDictated path, NEVER sent; strings web.composer.voice.* (8 keys, section-sorted, dupscan 0).

## Security trace (file:line at tip)
- endpoint-from-settings-only: src/Protocol/Methods/MediaMethods.php:524 configuredVoice() (VoiceConfig::fromSettings reads user settings; the wire carries no URL); happy-path dial URI asserted to start with the CONFIGURED endpoint in tests/Protocol/AudioTranscribeMethodTest.php (egress leg, plus Authorization-header-only assertion: key never rides the body).
- size bounds: MediaMethods.php:124 TRANSCRIBE_MAX_AUDIO_BYTES=8_388_608; :131 TRANSCRIBE_MAX_BASE64_BYTES=11_184_812 (< ServerConfig::MAX_CLIENT_FRAME_BYTES=16_777_216); :529 param ceiling at read; :539 strict base64 + decoded check before writeTake. 1-byte-window leg proves the decoded check is load-bearing (all-'A' 11_184_812 chars decode to 8_388_609 and are refused with nothing filed).
- Write-scope gating: MediaMethods.php:214-215 register() audio.transcribe Scope::Write sideEffects true; audio.status :221 Scope::Read; Dispatcher roster pin in AudioTranscribeMethodTest (names byte-consistent MethodSchemas⇄Dispatcher⇄schema, ProtocolSchemaDriftTest green at regen).
- key scrub: failed-transport leg — hostile 502 echoing the per-process key literal lands in the ledger as `[redacted]`, key absent end-to-end.
- recorder honesty: candy-code-web/src-web/lib/recorder.ts:119/:122 pre-prompt not-secure refusal (no silent browser-prompt claim), :126 no-supported-mime refusal BEFORE any getUserMedia prompt (no prompt for a format that will fail), :196 permission-denied mapped from the browser's own NotAllowed/SecurityError; pickEngine answers server ONLY when the flag is true AND the recorder API exists; dead-recorder degrade is visible (notice + recorderDead flag), never a silent lie. Engine truth is told via mic :title (voice.title names the endpoint; browser mode keeps mic_title).

## §1.8 in-step guard cascade
- MediaAffordanceTest + HelloMediaFlagsTest exact-array/quad pins moved in pick 1 (transcribe legs, door-honesty table leg, never-name-the-voice-URL clear leg).
- en.php ONE SORT_STRING run (web.composer.* section 60 keys, sorted YES) + PYTHON dupscan over the key column (whole-file dupes 0; grep -c NOT used per era-class trap).
- (CliLangCatalogueTest|LangParityTest):: = Tests: 15, Assertions: 4271 OK. (WebStringsTest|WebStringsDriftTest):: = Tests: 9, Assertions: 596 OK — with BOTH sanctioned mirrors committed in-step (candy-code/docs/protocol/web-strings.en.json via scripts/gen-web-strings.php --write, candy-code-web/src-web/lib/strings.en.generated.ts via npm run gen:strings; path-precise — NOT the trio).
- DiscoveryFile parseMedia UNTOUCHED by design (lenient triple-projection drops the extra key; seam noted for welder: the on-disk discovery record now embeds transcribe that parseMedia documents as a triple — behavior unchanged, doc shape only).
- DuplicatedTestHelperDrift: helpers renamed atm* stems; class-CONST collision caught live (ENDPOINT vs OAuthAuthorizeUrlTest) → renamed ATM_VOICE_ENDPOINT; green.
- TreeWideGuardRosterTest walks-by-text survive (green inside the 166T family). One-type-per-file checked. GlobDialect: new PHP test + specs carry no glob-shaped literals (mime strings are ';'-bearing, wildcard-free); green. No "Refused:" shapes; absence pins ride per-process uniqid/random_bytes sentinels.
- Word-numbers: MEDIA.md edits derived LIVE — no count prose added; the features.media roster sentence regenerated to name transcribe; DocFigureProseDriftTest re-run green (92T/4269A incl DocsQuickstart), no arm quotes the rewritten sentences (census: only an unrelated WebPushSenderTest hit).

## Protocol trio — regen-verify-RESTORE (deviation clause, stated per lane law)
The design's files.modify lists the generated surfaces; the LANE law forbids committing them, so the welder carries the regen. Executed here on the real code:
- `php scripts/gen-protocol-schema.php --write` (candy-code) then `node scripts/gen-protocol.mjs` (candy-code-web):
  - candy-code/docs/protocol/candy-code.v1.schema.json: +98/−1
  - candy-code/docs/SERVER.md: +2/−0 — PURE GENERATED (the two audio method-table rows); SERVER-MANUAL-SECTION.md **UNOWED** (6.l-tool precedent).
  - candy-code-web/src-web/protocol/generated.ts: +32/−1
  - candy-code-web/dist: NOT rebuilt — welder duty per brief (npm run build on merged tree).
- Drift guards green ON the regenerated tree: --filter 'ProtocolSchemaDriftTest|ServerProtocolDocumentationDriftTest' = Tests: 13, Assertions: 362 OK.
- Then all three surfaces `git checkout`-restored; final gates above re-run on the RESTORED committed tree (self-sufficiency proven); porcelain 0 (excl. prompt_kit evidence).

## Gates (targeted, plain pipe, cwd=candy-code -c phpunit.xml / vitest --no-file-parallelism)
- --filter 'AudioTranscribeMethodTest' = Tests: 10, Assertions: 55 OK.
- Server family (AudioTranscribe|MediaAffordance|HelloMediaFlags|MediaMethods|SecuritySweep|Transcriber|AudioRecorder|VoiceConfig|VoiceSession|TranscribeCmd|TreeWideGuardRoster|DuplicatedTestHelperDrift|DiscoveryFile|StateDir) = Tests: 166, Assertions: 4776 OK (pre- AND post-restore).
- Voice web family: recorder.spec.ts 16, dictation.spec.ts 19 (16+3 pickEngine), connectionMedia.spec.ts 6 (4+2 legs), composerVoice.spec.ts 6 (NEW), composerDictation.spec.ts 9 untouched-green → 5 files, 56 tests passed, re-run on the restored generated.ts.
- vue-tsc --noEmit: rc=0 (EXEC, clean).
- Full web rider (accepted-foreign, never fixed): `vitest run --no-file-parallelism` at tip = Tests 4 failed | 902 passed (906), Test Files 2 failed (stores/agents/__tests__/shellsInput.spec.ts ×2, components/panels/__tests__/processesSection.spec.ts ×2 — the pre-armed idempotencyKey family the brief named 28/4 at planning time; live shape today is 4 fails/2 files, all C-TOOL-6.l specs). Zero-contact proven: git diff name-only 31000e3be..HEAD greps 0 in agents|panels|shells|processes|fakes. NOT touched, NOT "fixed".
- Excluded per brief: PtyRunToolTest::testExpectMissIsLoudAndTheScriptStillContinues, RegistryTest shrink leg, ForkedChildReaper Sessions item (none fell inside my filters anyway).

## Foreign-red roster
Nothing new tripped. ReadmeSuiteFigureDrift staleness pair untouched (suite-figure.json/durations.tsv forbidden — my new test files ADD to the live enum: §7 below). GlobDialect 1F assert-NEITHER-number leg untouched (my edits moved no glob corpus: family green).

## Collisions
- cc/C-TOOL-6.g (MediaAffordance.php shared region): my flags() leg is an additive hunk, committed; no edit ever touched their files. No STOP needed.
- C-TOOL-6.b: no shared hunk materialized (MethodSchemas insert sits after media.fetch; their NotebookEdit/Read legs land elsewhere; re-derive from my blob if the weld collides).

## Deviations / seams (explicit)
1. Design files.modify lists generated trio surfaces — not committed (lane law; drift listed above; welder regens).
2. npm run build (dist) NOT run in-lane — welder duty per trio law; vue-tsc covers type truth instead.
3. Rider measured shape (4/2) differs from the brief's 28/4 label — same pre-armed family, live count today; accepted-foreign, unfixed.
4. Voice-gate refusal is Forbidden (no dedicated ErrorCode exists) carrying kind `voice_unconfigured` — house pattern (instruction_home_unavailable precedent).
5. composer mount() carries no pinia: connection() is lazy fail-soft (no-Pinia ⇒ honest 'no door' ⇒ browser engine) — the alternative (required plugin) would have rewritten the pre-existing composerDictation harness; both paths pinned.
6. E646 honored: no new wall-clock kill anywhere; upload rides the welded Transcriber's stall-detection defaults; the poll loop is client-side UI (bounded 240×500ms, stops on unmount/Esc).

## §7 new files for the battery (durations rows; live counts)
- candy-code/tests/Protocol/AudioTranscribeMethodTest.php — 10 tests / 55 assertions.
- candy-code-web/src-web/lib/__tests__/recorder.spec.ts — 16 tests.
- candy-code-web/src-web/components/__tests__/composerVoice.spec.ts — 6 tests.
(Modified, no new files: dictation.spec.ts 19, connectionMedia.spec.ts 6.)
Suite-figure/README counter re-derivation is the welder's on the merged tree.
