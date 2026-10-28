# C-PRV-5.b — independent review (round 1)

VERDICT: CLEAN

Commit 9868c7f8d on cc/C-PRV-5.b (base 33d015d4a), worktree /home/sites/cc-cmp-C-PRV-5.b. All findings below are PASS unless a severity tag precedes them.

## 1. Scope — PASS
`git diff --stat 33d015d4a..9868c7f8d`: exactly the 3 declared files —
candy-code/src/Cli/Bootstrap.php (+42), candy-code/docs/SETTINGS.md (+8/-1),
candy-code/tests/Cli/ToollessBackendThinkingTest.php (new, 217). No
suite-figure.json, no durations.tsv, no dist, no COMPARISON.md. Bootstrap hunks
all land in :9824-9981 (titleBackend docblock, summaryBackend docblock +
keepThinking arg, toollessBackend docblock + signature + config write);
lspClient() :8870 and workspace() :1797 are outside every hunk. keepThinking
grep: summaryBackend :9882 is the ONLY caller passing true; titleBackend :9836
uses the default. Matches the serialized [bootstrap:toollessBackend,
titleBackend, summaryBackend] [docs:SETTINGS.md] declaration.

## 2. THE CORE CLAIM ('' not unset) — VERIFIED, deviation from brief's literal mechanism is CORRECT
Factory read-path, all three thinking arms resolve
`$config['thinkingBudget'] ?? self::userTierSetting('thinkingBudget')`:
- ProviderFactory.php:1184 (createAnthropic, strict parser)
- ProviderFactory.php:1723 (gemini arm, tolerant parser)
- ProviderFactory.php:1760 (vertex arm, tolerant parser — the third consumer)
userTierSetting (:1145) reads `readUserConfig()['thinkingBudget'] ?? null`, so
an UNSET key genuinely loses to a user-tier value — unset would leak (e.g. a
root-tier 4096 parses whole through both parsers and rides the titler). The
claim holds.
'' is read as off on every wire:
- strict Anthropic parser :1008 returns null for null/''/[] explicitly ("the
  empty unset-${VAR} placeholder" is a generous off spelling per the docblock);
- tolerant parser :967: wholeNumber('') fails the `/^-?\d{1,18}$/` match →
  null → configuredThinkingBudget returns null → no thinkingConfig.
So the present non-null key beats `??` and both parsers map it to off — the
deviation is right; had the builder shipped the brief's literal unset this
reviewer would file it MAJOR (user-tier leak), and it did not.
Third-consumer census: `grep -rn thinkingBudget src/` shows no other reader of
the raw config key beyond these arms + the schema 'optional' lists (:129/:152/
:160, declarations only).
Fairness of the "no thinking arm at all" claim (effort door): the wire falls
back to `EffortBudget::thinkingTokens($request->reasoningEffort)` when the
provider budget is null (AnthropicProvider.php:411,
GeminiGenerateContentWire.php:276). toollessBackend constructs
`new EngineBackend(..., hooksDisabled: true)` with no withReasoningEffort
call, and zero Chat-side title/aux/summary callers set the per-turn effort →
toolless requests carry null effort; arm stays off on every real path. PASS.

## 3. Pins — 5 tests, both polarities, non-vacuous
tests/Cli/ToollessBackendThinkingTest.php read in full:
1. titler block tier: block 8192 → asked budget assertNull (real
   Bootstrap::titleBackend()).
2. titler user tier (THE discriminator for ''-vs-unset): writeUserConfig
   {thinkingBudget:4096}, block key absent → assertNull. Under a bare unset()
   this test alone reddens (4096 rides the `??`); the in-file comment says so
   and the mechanism is confirmed against the factory code above.
3. summary block tier: 8192 survives via real Bootstrap::summaryBackend().
4. summary user tier: 4096 survives — proving the keepThinking path keeps BOTH
   feeds, and demonstrating the `??` fallback is live (symmetry that
   strengthens test 2).
5. vacuity arm: raw factory create with 8192 asks 8192 — the fixture genuinely
   feeds the constructed provider.
Capture seam: reflection over the provider the REAL factory paths built
(EngineBackend::$provider), reading AnthropicProvider::anthropicThinkingBudget
— the raw "what was asked" input to the wire arm. The null ⇒
assertArrayNotHasKey('thinking') byte mapping is pinned by
ThinkingCeilingTest (:120/:133/:177/:201 vertex/:217 gemini
thinkingConfig-absence confirmed on disk), whose family filter is in the gate
set; the test-file header docblock is honest about this division of labour.
Note: the brief phrase "the title backend's request has no thinking key" is
thus satisfied across the two-file pin pair, not in one literal body-dump
assert — no finding, just recorded. setUp follows the lane-cf HOME-sandbox
contract (snapshot first, re-arm after the env loop).

## 4. AnthropicProvider::withModel — PASS
`git diff 33d015d4a..9868c7f8d -- src/Providers/AnthropicProvider.php` is
0 bytes. The carry becoming moot is the correct consequence of a null budget.

## 5. SETTINGS.md — PASS
Hand prose sits in the `thinkingBudget` bullet at :507-533; the generated
table marker region starts at :1061 — the edit is outside it. C-PRV-5.a's
:521 family-ceiling sentence ("A `thinkingBudget` larger than the configured
model's family output ceiling — 64000/65536…") is intact byte-for-byte; the
new sentences were inserted between the bill sentence and it. Prose truth:
"not sent on title and auxiliary calls — session titles, /btw, goal
judgements, commit subjects" matches the consumers of toollessBackend;
"The summary backend is the one exception by design (C-PRV-5.b)" matches the
sole keepThinking caller. Drift: SettingsSchemaDocDriftTest green in gate
(the direct `php tools/gen-settings-doc.php --check` invocation was
permission-refused to this reviewer; the guard test is the phpunit-routed
equivalent and it passes).

## 6. Gates — reproduced EXACT (plain pipe, cwd worktree candy-code, env -u LINES -u COLUMNS, targeted only)
- --filter ToollessBackendThinkingTest: Tests: 5, Assertions: 14 — OK (4.5 s)
- --filter 'ThinkingCeiling|AnthropicExtendedThinking': Tests: 57, Assertions: 141 — OK
- --filter 'SettingsSchemaDocDrift|TrustKey|DocFigureProseDrift': Tests: 128, Assertions: 4685 — OK
- five-guard (ReadmeSuiteFigureDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift + DocFigure in the run above): Tests: 42, Failures: 2 — ONLY the expected staleness pair (25537 pinned vs 26237 live; closeout re-pin owns it).
Every run carries PHPUnit Deprecations: 2 — the disclosed pre-existing repo-wide
AccessorDeprecationTest non-public-provider family, identical on the untouched
neighbor runs. No PtyRun-shaped filter involved.

## 7. Ledger/hygiene — PASS
REPORT.md lists the DUR row (tests/Cli/ToollessBackendThinkingTest.php, 5T,
~4.4 s worst-class) for the gate welder. Author: %ae and %ce both read
detain@interserver.net (fields shown separately in the format string —
unambiguous), %(trailers) empty, zero Co-Authored. §1.9: no glob-shaped or
Refused-shaped literals in the new file (temp dir via bin2hex(random_bytes),
'sk-test' is plain); no fabricated-success paths — every claim above was
re-derived from the on-disk code, not from the REPORT.
