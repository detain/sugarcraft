# C-SES-8.g FIX-1 (round 1) — lane cc/C-SES-8.g, base tip 4202b510e

## M1 — LayeredSettingsTest census roster flip (MAJOR)
- tests/Config/LayeredSettingsTest.php: literal extended with 'transcriptJsonl' after 'vimMode'
  (matches LAYERED_KEYS order :642/:643 and the array_diff derivation).
- Gate: --filter LayeredSettingsTest → 99T, exactly 1F = testTheUserTierOnlyKeys... ; the residual
  diff legs are ONLY 'promptCache.keepWarm' (unexpected index 17, shifting every later index —
  transcriptJsonl now appears on BOTH sides). FOREIGN-BASE LEG DISCLOSED, NOT cured (not this
  step): keepWarm present in LAYERED_KEYS at base 740c29868:src/Config/LayeredSettings.php:546 and
  on master (:546); test literal grep-counts 0 keepWarm at base AND at master. Standing-red law:
  owner/closeout decides that cure.

## m1 — TranscriptResumeTest structural position-leg vacuity (MINOR, step-local)
- tests/Session/TranscriptResumeTest.php:345+ NEW gap assert: the text between
  mb_strpos('writeTransaction(') and mb_strpos('$this->mirrorTranscript(') MUST contain
  '$wrote === true' — fails the in-closure-tail variant that position-alone passes.
- Mutation-proven: mirror moved to closure tail (before `return true; }, ...`… actual anchor
  `syncSearchIndex...return true; });`), guard removed → EXACTLY this new leg reds
  (TranscriptResumeTest.php:349, Tests:1 Assertions:4 Failures:1). cp-restore md5
  ea0442217b0fd583b228e14dd88068d7 verified. Post-restore filter TranscriptResumeTest: 14T/65A OK
  (+1 assertion vs the 64A review figure = the new leg).

## m2 — ScriptHook inline FQCN (NIT)
- src/Hooks/ScriptHook.php: added `use SugarCraft\Code\Cli\LaunchContext;` (alphabetical head of
  the use block; Hooks→Cli imports already tree-wide per grep) and shortened the stdinPayload call.
- php -l clean; filter ScriptHookTest 84T/405A OK (claim exact).

## Gates (all cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)
- LayeredSettingsTest: 99T / 1F (foreign keepWarm leg only) — expected shape.
- TranscriptResumeTest: 14T/65A OK. ScriptHookTest: 84T/405A OK.
- 6-class doc/guard batch SettingsSchemaDocDrift|TrustKey|ConfigWriteProducer|ReadmeRoster|
  DocFigureProse|SettingsSchemaTest: 200T/7680A GREEN (reviewer's base-era figure was 194T/7661A;
  delta = master-era derived provider rows, zero failures).

## Commit (in-lane, early)
- 55d425d31cb8462c274b220be660573a368314a9 "C-SES-8.g: review fixes (round 1)" (cat-file -t = commit;
  3 files +12/-2). %ae grep=1 detain@interserver.net, %ce separate grep=1, Co-Authored-By count 0.
- git log master..HEAD: 4202b510e + 55d425d31. status --porcelain: clean.
