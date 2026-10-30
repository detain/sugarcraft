VERDICT: FINDINGS — MAJOR×1, MINOR×1, NIT×1. Otherwise honest, well-wired, reachable.
Review r1 of cc/C-SES-8.g @ 4202b510e (base 740c29868, single commit). Reviewer: fresh r1 lane. 2026-10-30.

## 1. Traceability and scope — PASS
- Diff: 11 files +356/−43 — README.md, docs/HOOKS.md, docs/SETTINGS.md, lang/en.php,
  src/Cli/Bootstrap.php, src/Config/LayeredSettings.php,
  src/Config/Settings/Definitions/InterfaceSettings.php, src/Hooks/ScriptHook.php,
  src/Session/EnhancedSessionStore.php, tests/Hooks/ScriptHookTest.php,
  tests/Session/TranscriptResumeTest.php. Builder stat claim EXACT (git diff --stat).
- ZERO new test files (git diff --diff-filter=A empty) → no durations.tsv row owed, claim true.
- ZERO SessionHost.php / UsageRecordsTest.php touches — matches the claim that the lane-base
  UsageRecordsSettle-arm red is foreign (cured on master by c013d930d).
- README.md + LayeredSettings.php are not in the block's files-modify list but both are FORCED
  by §1.8 in-step guard law (SettingsSchemaTest + SettingsSchemaDocDriftTest + ReadmeRoster +
  DocFigureProse census equality against LAYERED_KEYS and doc counts). Not a scope violation.
- No weld-only files (suite-figure.json, durations TSV, dist/) in diff. Author law verified:
  %ae=%ce=detain@interserver.net, Joe Huss, zero Co-Authored-By. php -l clean on all src files.

## 2. Completeness — PASS
No stubs/TODOs/not-implemented. All block deliverables land: setting definition, Bootstrap
const + sessionStore() mirror arm, post-commit never-throw mirror, 7th hook key, lang rows,
HOOKS.md six→seven, SETTINGS.md/README/LayeredSettings regen.

## 3. Correctness / reachability — PASS (E715-trap cleared)
- Bootstrap::sessionStore() (:10179 area): single `return $store;` at tail; the mirror arm
  `if (UiSettings::bool(self::TRANSCRIPT_JSONL_SETTING)) { JsonlTranscriptWriter::setEnabled(true); $store->withJsonlMirror(new JsonlTranscriptWriter(projectRoot: LaunchContext::projectRootForSettings())); }`
  precedes it — no early return can skip it. UiSettings::bool is a real static reader (:108).
  All DIRECT static calls — no method_exists / dynamic resolution anywhere in the new path.
- ScriptHook::stdinPayload transcript_path reads JsonlTranscriptWriter::enabled() and
  pathFor(...) directly — static-analysis-visible. Hook path and store path share one writer
  class, so hook-reported path and written file cannot drift. pathFor never creates; when the
  root is unset base='' and save() returns false — harmless, pinned.
- saveTranscript: $wrote = writeTransaction(closure); tombstone arm returns false; mirror only
  `if ($wrote === true)`; mirrorTranscript null-guards and catches \Throwable around save().
  writeTransaction (:415) returns mixed and rides the bool through immediateTransaction.
- Sole EnhancedSessionStore construction site = Bootstrap.php:10141 (inside sessionStore());
  every live saveTranscript caller (DebouncedTranscriptWriter:128, Host/TranscriptStore:149,
  AgentManager:631, BackgroundSessionRunner:846, SessionImporter) rides that store. Off→on
  hand-trace: off = no setEnabled, hooks get present-null; on = mirror writes JSONL at
  .candy-code/transcripts/<slug>/<id>.jsonl 0600 and hooks carry the path.

## 4. Findings
### MAJOR M1 — LayeredSettingsTest census roster not flipped in-step (§1.8 census law)
- candy-code/tests/Config/LayeredSettingsTest.php:66
  testTheUserTierOnlyKeysAreExactlyTheLayeredKeysNoProjectMaySet is RED at tip.
  Derived userTierOnlyKeys() now contains 'transcriptJsonl' (lane extended LAYERED_KEYS at
  LayeredSettings.php:512) but the expected literal roster was not extended → the in-step
  flip was missed. Builder's 238T/48306A guard batch evidently excluded LayeredSettingsTest
  (my 7-class batch measured 293T/7895A with exactly this 1F).
- Attribution (disk-proven): the failure diff shows TWO surplus derived entries —
  'promptCache.keepWarm' (+17) and 'transcriptJsonl' (+75). keepWarm was ALREADY in
  LAYERED_KEYS at base (git show 740c29868:…LayeredSettings.php:546) while the test literal
  grep-counts 0 keepWarm at both 740c29868 and c013d930d → keepWarm leg is a FOREIGN base red
  (standing_reds law: not this lane's). The transcriptJsonl leg IS lane-attributable.
- FIX: add 'transcriptJsonl' after 'vimMode' in the expected literal of that test (same
  logical commit; lands as fix). The test will REMAIN red on the foreign keepWarm leg —
  fixer must disclose that leg as out-of-scope base state (or the closeout owner decides to
  cure it separately; do not silently fold a foreign cure in without a note).

### MINOR m1 (step-local) — partially vacuous structural pin for DISCLOSED DEVIATION 2
- candy-code/tests/Session/TranscriptResumeTest.php
  testTheMirrorCallIsStructurallyBehindTheCommitAndBehindACatch asserts
  mb_strpos(saveSlice,'$this->mirrorTranscript(') > mb_strpos(saveSlice,'writeTransaction(').
  'writeTransaction(' lexically precedes the ENTIRE closure body, so moving the mirror call
  INSIDE the closure (at its tail) still passes the position assert; no functional test
  catches that variant either (rolled-back test's JsonException fires mid-closure before a
  closure-tail mirror; tombstone/append pins unchanged). Only mirror-BEFORE-the-transaction
  and mirror-ungated-after are functionally caught. The try/catch and never-throw legs of the
  pin are genuine; the post-commit-position CLAIM is overstated.
- FIX: also assert the text gap between the commit position and the mirror position contains
  '$wrote === true' — fails under the in-closure variant, keeps the structural claim honest.

### NIT m2 — candy-code/src/Hooks/ScriptHook.php
New inline FQCN '\SugarCraft\Code\Cli\LaunchContext::' inside stdinPayload (class not
imported). Cosmetic; house style imports it elsewhere.

## 5. Judgments on disclosed deviations
- DEVIATION 1 (by-set withJsonlMirror, not immutable clone): ACCEPT. The design block itself
  names withJsonlMirror; the store is a service holding a live PDO + WeakMap memo state — a
  clone would fork live identity; Bootstrap discards the return consistently; identity is
  pinned (assertSame($store, $store->withJsonlMirror(...))).
- DEVIATION 2 (mirror never-throws pinned structurally because JsonlTranscriptWriter is
  final and cannot be doubled): ACCEPT the shape; the gap is m1 above.
- DEVIATION 3 (generated layered-count 85 not 86): VERIFIED TRUE. Counts match DERIVED
  values and every spelling/count guard is green: README 'one hundred and forty-three'
  (142→143) + 'eighty-five keys never' (84→85) + transcriptJsonl never-project rationale
  sentence; docs/SETTINGS.md 142→143, restart 79→80, env-split 24 unchanged.
  DocFigureProseDrift/TrustKey/ReadmeRoster/SettingsSchemaDoc all green (194T/7661A batch).

## 6. Measured gates (reviewer, plain pipe, cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS)
- TranscriptResumeTest: 14T/64A OK (builder claim exact).
- /ScriptHookTest/ : 84T/405A OK (claim exact). Broader 'ScriptHook' filter 149T green incl.
  ScriptHookJsonStdoutTest 27T/73A — grep proves it does not pin the key list, so leaving it
  untouched is design-legal.
- Guard batch SettingsSchemaDocDrift|TrustKey|ConfigWriteProducer|ReadmeRoster|DocFigureProse|
  SettingsSchemaTest: 194T/7661A GREEN.
- LangParityTest 7T/3333A green. DuplicatedTestHelperDrift|SettingsWriterCensus|EnvRosterDrift
  56T/45858A green.
- 7-class batch incl. LayeredSettingsTest: 293T/7895A, 1F = M1.
- TranscriptStoreTest|UsageRecordsTest: 39T/221A, 1F = UsageRecordsTest settle-arm '3 is
  identical to 2' — pre-existing lane-base red, cured on master by c013d930d (touches only
  Chat.php + UsageRecordsTest.php); foreign, not lane-attributable.
- gen-settings-doc --check: NOT RUN — reviewer permission map denied the tool 3 attempts
  (any path shape). Substituted by green SettingsSchemaDocDriftTest + DocFigureProseDriftTest
  which assert the same derived-vs-doc equality. Disclosed as substitution.

## 7. Conventions / project laws
strict_types + PSR-12 hold; lang label/help keys alphabetical placement verified; Lang::t used
for user strings; §6 dictates no ruling on this step and the lane invented none; E646 N/A (no
LLM-call timeouts added); no proc_open sites added.

## Verdict
FINDINGS: fix M1 (roster flip + foreign-leg disclosure) and optionally m1/m2 in one fixer
pass, then r2 re-review. All builder factual claims disk-verified true; the lane's own work
is sound and reachable.
