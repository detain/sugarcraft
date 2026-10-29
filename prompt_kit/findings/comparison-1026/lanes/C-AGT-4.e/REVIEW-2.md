# C-AGT-4.e REVIEW-2 (r2) — fresh reviewer @ b5615c9da
VERDICT: CLEAN

All six r1 findings cured, disk-verified (fix = 5273e6b78 + b5615c9da; 6 commits on 44ff31314):
1. CRITICAL gate-trim: PermissionGate.php:1811 `str_starts_with(trim(...), SESSION_PREFIX)` — byte-symmetric with execute() :236; pins c3 :165 + c4 :169 (space/tab → ASKED), c5 :175 padded-plain stays no-ask. Trim removed → carve-out blind → both assertFalse legs red. NBSP divergence impossible (same default trim both sides); non-string `to` is_string-gated and execute casts to ''.
2. MAJOR ToolDocDrift: README:2508 roster line regenerated via gen-tool-docs (1-hunk, gloss matches); guard green at tip.
3. MAJOR ReadPathCensus: 3 rows added IN the fix commit (not pre-existing): file_get_contents×2 OWNED_HOME, fopen×1 OWNED_HOME, scandir×1 NAMES_ONLY — verdict words match take()/readKey()/mintKey(); family filter red-free.
4. MAJOR drain pins: SessionHostMailDrainTest (3T) + SessionMailWakeTest (3T). Shell mutation harness blocked here (mkdir/cp denied) — static reasoning: deleting tick's mail foreach zeroes $fired → all three assertSame(1,tick) legs red, LoopFire untouched; deleting Chat's $mail block → wake pins :79/:80 and :104 red (null Cmd); busy pin by-design targets inFlight-gate removal only (header declares it).
5. MINOR docs/AGENTS.md: '### Messages to another session' at :346, content-accurate, path declared in files.modify.
6. MINOR sessionStoreHandle: trustedConfigDirPath()+is_file now inside try, catch \Throwable → null; pin :191 (0o777 home → refusal, never throw).
Invariants hold: SessionMailbox.php diff 1431b98fc..b5615c9da EMPTY (containment, 0700/0600, publish-rename, take()-once, HMAC hash_equals, forged-tag carry byte-identically); zero proc_open/fork/timer in src additions (E646); authors %ae/%ce detain 6/6, no trailers; worktree clean; suite-figure/durations/dist untouched.
Gates (cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe): mandated filter 734T/28,436A/0F/0E, 2 pre-existing deprecations; adjacent guards DuplicatedTestHelperDrift|GlobDialect|DuplicatedDocBlockLine green — including over the two plant() twins (self:: vs $this:: is within drift bound); LangParity|LangLaunchLocale 20T green (en-only keys licensed).
CARRIES (welder duty, not lane findings):
(a) DUR rows for the 4 NEW *Test.php: SessionMailboxTest, SendMessageCrossSessionTest, SessionMailWakeTest, SessionHostMailDrainTest.
(b) OUT-OF-SCOPE MINOR, pre-existing at base 44ff31314 (NOT lane-introduced; src/Chat.php:13712 untouched by lane): SymbolCitationDriftTest :820 red — cite \SugarCraft\Code\Agents\AgentPresetFieldCarryTest lacks the Tests\ segment (real class …\Code\Tests\Agents\…). Fix: add `Tests\` to the cite — exact W18 rider pattern.
NIT (no action): FIX-1's "trim removal → exactly 1F at :165" understates — :165 AND :169 both redden; immaterial.
