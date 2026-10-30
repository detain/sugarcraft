VERDICT: CLEAN — 0C/0M/0 step-local minors, 2 NITs (report-accuracy + env noise). r2 of cc/C-SES-8.g @ 55d425d31 (build 4202b510e + fix 55d425d31, tree clean, 2 commits). 2026-10-30.

## Fix verification (all re-derived on disk, not trusted from FIX-1)
- M1 FIXED: tests/Config/LayeredSettingsTest.php:68 literal now carries 'transcriptJsonl' after
  'vimMode'. Gate --filter LayeredSettingsTest = 99T/234A, EXACTLY 1F (testTheUserTierOnlyKeys...).
  Array reconstruction from the raw diff: transcriptJsonl appears on BOTH sides (expected 73 /
  actual 75 — pure index-shift cascade); the only UNEXPECTED derived entries are
  'promptCache.keepWarm' AND 'redactToolOutput'. Both disk-proven foreign: present in
  LAYERED_KEYS at base 740c29868 (:546, :562) AND master (:546, :562), grep-count 0 in the test
  literal at base, 4202b510e, master AND tip. userTierOnlyKeys() = LAYERED_KEYS minus
  PROJECT_TIER_KEYS (LayeredSettings.php:1016-1019), so the same 1F exists at base — the fixer's
  own leg contributes ZERO. NIT-1: FIX-1's "residual legs ONLY promptCache.keepWarm" is
  incomplete — the foreign roster is TWO legs (see lead note). Curing only keepWarm at closeout
  leaves this test red.
- m1 FIXED + functionally adequate: TranscriptResumeTest new leg (assertStringContainsString
  '$wrote === true' over mb_substr($saveSlice,$commit,$mirror-$commit)). Current code:
  $wrote = writeTransaction(...) at EnhancedSessionStore.php:2021, gate `if ($wrote === true)`
  :2099, mirror :2100 — gate text provably inside the gap. Closure-tail variant: gate cannot
  exist inside the closure args → leg reds; mirror-before-commit variant: assertGreaterThan reds.
  Pin now matches its structural claim. Gate: TranscriptResumeTest 14T/65A OK (+1A vs r1's 64A,
  exact per claim).
- m2 FIXED: ScriptHook.php use-import added (alphabetical head), call shortened, php -l clean,
  LaunchContext::projectRootForSettings() is public static (LaunchContext.php:1409).
  ScriptHookTest 84T/405A OK (claim exact).

## r1 PASSes re-confirmed at new tip (fix = 3 files: ScriptHook.php, LayeredSettingsTest.php,
TranscriptResumeTest.php; +12/−2 — everything else unchanged)
- Reachability: setting def InterfaceSettings.php:346; LAYERED_KEYS :643; lang label+help rows;
  Bootstrap const :10300; sessionStore arm :10187-10191 DIRECT statics precede sole tail
  `return $store;` — no early return; ScriptHook transcript_path present-null-off, no
  method_exists anywhere.
- Guard batch SettingsSchemaDocDrift|TrustKey|ConfigWriteProducer|ReadmeRoster|DocFigureProse|
  SettingsSchemaTest at tip: 200T/7680A GREEN (FIX-1 claim exact).
- Zero new test files (git diff --diff-filter=A master...HEAD empty) → no durations.tsv row owed.
- Author law: both commits %ae=%ce=detain@interserver.net (separate greps), master..HEAD
  Co-Authored-By count 0. No weld-only files in diff.
- D1 unchanged (withJsonlMirror :2114 by-set, identity-kept); D3 doc figures untouched by fix and
  the green doc-guard batch is the same derived-equality proof r1 used (README :1286
  "one hundred and forty-three" verified on disk).

## NIT-2 (non-lane): every filtered run prints "PHPUnit Deprecations: 2" including untouched
classes — global phpunit.xml/config noise, not introduced here; no action.

## Lead note (regardless of verdict)
§7/master duties, NOT lane-blocking: (1) LayeredSettingsTest foreign base-red = keepWarm
AND redactToolOutput (2 legs, both in LAYERED_KEYS at base/master, absent from the literal at
base/master — fixer/r1 named only keepWarm); (2) UsageRecordsTest-era staleness pair — r1
attributed the settle-arm cure to master c013d930d, fix commits don't touch it.

Reviewed gates (cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe):
LayeredSettingsTest 99T/1F(foreign-pure) · TranscriptResumeTest 14T/65A · ScriptHookTest 84T/405A
· guard batch 200T/7680A. gen-settings-doc --check not re-run (same permission denial as r1;
substituted by green SettingsSchemaDocDrift+DocFigureProse arms).
