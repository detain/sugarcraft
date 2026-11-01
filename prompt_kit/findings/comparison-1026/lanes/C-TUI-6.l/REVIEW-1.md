# C-TUI-6.l — REVIEW-1
Lane: /home/sites/cc-cmp-C-TUI-6.l · branch cc/C-TUI-6.l · REAL BASE 85bfba37f · THREE picks: 61c449790 (voice settings family + VoiceConfig, 8 files) · 8f861e657 (voice core: AudioRecorder/AudioRecording/VoiceConfig/VoiceSession/Transcriber/TranscriptionException/TranscribeCmd/2 Msgs + guard rows + docs/MEDIA.md, 11 files) · 51cc0fdb0 (stat-cache honesty + key-redaction fixes + five new tests, 8 files) · tip 51cc0fdb0. 25 files +2592/−12, porcelain 0. Law re-derived: %ae/%ce separate greps 3/3 detain, Co-Authored 0. This is the FIRST genuine review artifact for the step (evidence dir held only REPORT.md, 7,485 B; builder Status-line "IN PROGRESS" = doc-lag, lane closed — judged on substance).

VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 1 MINOR(step-local) / 2 NIT

## Q1 Data-flow + PRIVACY — PASS (no key/byte-egress path found: n)
End-to-end blob-traced from lane blobs:
- VoiceConfig::fromSettings reads the five keys at arm/transcribe moments (never a launch cache); fail-soft []; maxSeconds clamped 5–600; enabled()⇔baseUrl≠'' — design ADOPT-ruling holds (voice OFF until user-tier baseUrl; audio never dials by default).
- AudioRecorder: argv-array shapes exactly per design (`arecord -d <cap>…<take>` / `sox -t … trim`); user values reach ONLY argv elements (spawnSpec(proc_open array form) — no shell interpolation anywhere); probe()→ProcessContainment::locateOnPath arecord→sox→''→REFUSAL_NO_BACKEND refusal-CLOSED (host has NO arecord/sox — which-empty measured; the real default-probe path answers no_backend here).
- Take files: reserveTake lstat plant-refusal → fopen 'xb' under umask 0077 → chmod 0600 BEFORE payload (structural order pinned); dir 0700 PrivateDir::ensure; under owned home ~/.candy-code/cache/voice — no world-writable path, no /tmp.
- Transcriber: key read ONLY as Authorization Bearer via expandEnvPlaceholders (${VAR} / ${VAR:-def}; unset→empty→NO header at all — testMissingEnvKeySendsNoAuthorizationHeaderAtAll); error excerpt = Sanitize::untrusted → str_replace(expanded,'[redacted]') → mb_substr 240 (M2 target, real discriminator with uniqid sentinel echoed in a 401 body); transport() names exception CLASS only; audio bytes never land in any message; WAV read once, uploaded, unlinked in finally.
- VoiceSession/TranscribeCmd: refusal tokens→Lang::t media.voice.*; msgs carry text/line only (doc-commented key-free); run() Throwable→'unexpected '.type (no message passthrough). Zero error_log/STDERR/Log:: call sites in src/Media/Voice/ + Msgs (grep empty). Cleanup on EVERY exit path incl pre-dial refusals: guards sit INSIDE the try so finally-unlink fires (off/silent refusal tests assert file deleted + dials==0 — M3 shape).
- No new spawn surface beyond recorder/sox argv; fork child uses pre-existing ForkedChild::closeInheritedServerFds + ToolIpcFiles::write + exitNow(0) (all blobs zero-diff, reuse verified by name-grep rc=1).

## Q2 E646 + containment — PASS
- Per-op bounds only: CURLOPT_LOW_SPEED_LIMIT=1024 / LOW_SPEED_TIME=60 + connect bound via HttpClientDefaults (pre-existing doctrine "A total timeout must NOT be added here"); options-level 'timeout' NEVER set — pin testTheRequestIsBounded… is runtime assertArrayNotHasKey('timeout') PLUS structural source-slice banning the spelled key in transcribe() body (non-vacuous, real discriminator).
- TranscribeCmd parent: periodic 0.05s waitpid(WNOHANG) timer, NO wall-clock kill of the transcribe (settles when child settles); stop() uses SIGINT→1.5s bounded read→ProcessReaper::escalate TERM/KILL→proc_close (ladder, mirrors welded 6.j discipline).
- ZERO FFI/cdef/intptr_t hits in the whole pick-range diff (token grep).
- Containment reuse proven: ProcessContainment/SandboxFactory/ScriptHook/WindowsShell/PosixShellQuote/ForkedChild ALL zero-diff across 85bfba37f..HEAD; voice routes THROUGH spawnSpec/terminate/scrubbedEnv/locateOnPath — no second containment engine.

## Q3 Settings cascade + §1.8 — PASS
- Five keys in LAYERED_KEYS (:603-607); ZERO voice keys inside PROJECT_TIER_KEYS block (grep count 0) ⇒ user-tier-only by derivation; VoiceConfigTest::testTheWholeVoiceFamilyIsUserTierOnly pins all five via LayeredSettings::userTierOnlyKeys()+projectSettable false+envVar null (derived, not literal).
- Lang: dupscan sort|uniq -d = 0 dup keys over 4009 extracted literals; added label/help/media.voice runs ALL sorted in place; CliLangCatalogueTest 8T/220A and LangParityTest 7T/4028A green (anchored stems, non-zero).
- Mirrors UNOWED-PROVEN: gen-web-strings carries ONLY web.-prefixed keys; voice adds zero web.* keys; the settings catalog never rode the mirrors (settings.sd.* absent) ⇒ mirrors regen-consistent by N/A. Protocol trio (docs/protocol/candy-code.v1.schema.json, docs/SERVER.md, src-web/protocol/generated.ts) + candy-code-web/dist ABSENT from all three picks (name-list re-derived; none moved ⇒ no welder drift owed there).
- SETTINGS.md + README voice rows + word-numbers ("one hundred and fifty-three" layered, "ninety-four" never-project) landed SAME-COMMIT in 61c449790; ReadmeRosterDrift + SettingsSchemaDocDrift (19T/203A) + DocFigureProseDrift + EnvRosterDrift all green in my runs ⇒ derive-laws hold.
- Rosters same-commit, tight keys: TreeWideGuardRosterTest +2 rows (Transcriber/VoiceSession scandir walkers) — BARE run 17T/3969A green; ReadPathCensus ×3 rows (BARE-family run 19T/773A green); DescriptorInheritanceGuard ×1 row (15T/5191A green). Added-line shape-scan for fopen/scandir/proc_open/env sites found no site row-missing-for: every new IO call site maps to a landed row.
- DuplicatedTestHelperDrift BARE 14T/204A green across the five siblings (no one-token-divergent helpers).
- check-one-type-per-file: builder claims rc0 (62 libs/3381 files/0 problems) — `php tools/…` DENIED to this reviewer ⇒ UNCONFIRMED-BY-RUN (pin-adjacent doc guards all green independently).
- GlobDialect: zero glob-shaped quoted literals in added lines (audio temp patterns use sprintf tokens, not wildcard literals); zero **Refused:** shapes; uniqid sentinels where secrets are planted. The 1F GlobDialectDifferentialTest (docblock 310,245 vs derived 311,360=560×556, i.e. +1/+1) re-derived BY MY MEASUREMENT as BASE-CARRIED: blame of the four accused literals lands f05851491/a3a94d980/15776c8df/875f66d6c and `git log 85bfba37f..<sha>` = 0 for all four — never-touch, welder-side docblock rot predating this lane (assert-NEITHER honored: I cite both figures from the message).
- SuiteSkipRoster: zero markTestSkipped added in tests/Media/Voice/ (grep empty) — recorder needs are gated on PLANTED shims, fail-LOUD otherwise.

## Q4 Gates — builder figures reproduced EXACT
cwd=candy-code, `-c phpunit.xml`, `env -u LINES -u COLUMNS` plain pipe, anchored full-stem filters (each run also carried the pre-existing "PHPUnit Deprecations: 2" pair):
- AudioRecorderTest 12T/58A OK · VoiceConfigTest 6T/45A OK · TranscriberTest 12T/58A OK · VoiceSessionTest 6T/19A OK · TranscribeCmdTest 8T/28A OK → combined five-class 44T/208A OK.
- Bare TreeWideGuardRosterTest 17T/3969A · bare DuplicatedTestHelperDriftTest 14T/204A · SettingsSchemaDocDrift 19T/203A · CliLangCatalogue 8T/220A · LangParity 7T/4028A · ReadPathCensus 19T/773A · DescriptorInheritanceGuard 15T/5191A — ALL green.
- Guard battery (DocFigure|EnvRoster|ReadmeRoster|ForkedChildExitConvention|ChildStderrCapture|StderrEmitterCensus|GlobDialect) 258T/72256A/6F — all six reds in adjudicated foreign families (names verified by message: StderrEmitter ×2 PtyRelay-debt, ChildStderrCapture ×2, ForkedChildExit ×1 names ONLY src/Background/PtyRelay.php::main:213, GlobDialect ×1 base-carried). ZERO red names Media/Voice.
- Mutation-EXEC: /home/sites/rev-6l-r1-mut seed dir ABSENT and `cp -a` DENIED to this reviewer ⇒ M1/M2/M3 UNCONFIRMED-BY-RUN here, credited builder-EXEC'd; I read all three target pins and each is a real discriminator (M1 stop-reads-back w/ bounded 2.0s wait; M2 echoed-key scrub; M3 refusal-deletes-take). PtyRunToolTest::testExpectMiss + RegistryTest shrink + ForkedChildReaper exclusions honored (never in my filters); no full suite run.

## Q5 Foreign adjudication
My battery actuals resolve to the known families ONLY (StderrEmitterCensus ×2 / ChildStderrCapture ×2 / ForkedChildExitConvention ×1 / GlobDialect ×1) — no NEW family appeared. No voice-touched foreign red is moved. Pairing: at MY lane tree the co-run shape is green except the four foreign F above; staleness pair (suite figure) never touched here — voice adds +44T-class, DISCLOSED for welder re-pin (below).

## Q6 Inventory + weld-readiness — WELD-ELIGIBLE: YES
- Name-set = the 25 paths REPORT declares (extras: none). Zero weakened legs found; zero unrostered skips; residue 0.
- MINOR-1 (step-local, REPORT hygiene): builder Status-line still says IN PROGRESS while the lane is closed with all gates recorded — one-line doc fix at weld or by builder.
- NIT-1: VoiceSession register_shutdown_function + no-pcntl sync fallback are honest UNCONFIRMED-by-run (structural pins only) — builder labeled them as such; fine.
- NIT-2: none other.

### §7 LIST (welder duty — NEVER fix here)
1. FIVE durations.tsv rows at MY live counts: tests/Media/Voice/{AudioRecorderTest,VoiceConfigTest,TranscriberTest,VoiceSessionTest,TranscribeCmdTest}.php — canonical mint = serial green-junit regen; observed walls (bootstrap-dominated ~10.3–11.1s): AudioRecorder 11.088 / VoiceConfig 10.390 / Transcriber 10.366 / VoiceSession 10.295 / TranscribeCmd 10.494.
2. Suite-figure/README pair: +44T-class carry (lane adds 44 tests / 208 assertions) — re-pin at battery per cadence.
3. Trio/dist UNOWED (absent from all picks; mirrors N/A-proven). GlobDialect docblock rot 310,245→311,360 is BASE-carried — NOT this weld's duty (report-only).
4. WELDER WARN: base is 85bfba37f (ff-merged onto C-TOOL-6.h last-pick while zero-picks), NOT 13f9a1829 — three EXPLICIT picks 61c449790 / 8f861e657 / 51cc0fdb0, read-from-lane blobs, NEVER a range onto moved master.
5. COLLISION-UNION flags (expected-adjacent, cure not demanded): vs landed 6.a/6.d ProcessContainment/CapturesProcessOutput/Bash :535 double-quote preservation (6.l REUSES those helpers — zero-diff verified here, but union-check text on master at weld); vs landed 6.h/6.i Dispatcher/PermissionGate ordering user__→client__→mcp__ + ToolRegistry (6.l does NOT touch ToolRegistry — TranscribeCmd is a TEA cmd, not a registry slash tool); vs IN-FLIGHT cc/C-TOOL-6.k: shared README.md + docs/SETTINGS.md + lang/en.php + tests/TreeWideGuardRosterTest.php (both append rows — expect textual conflicts, resolve as union); vs read-only cc/C-TOOL-6.g: shared docs/MEDIA.md + tests/Support/ReadPathCensusTest.php (union; 6.g holds slot priority when its verdict lands). conflict=STOP.

Privacy verdict: NO key material, transcript, or audio byte reaches any stderr/log/notice/announce path (traced above). E646: clean. Containment: reuse-only. Findings: 0C/0M/1 MINOR(step-local doc-lag)/2 NIT.
