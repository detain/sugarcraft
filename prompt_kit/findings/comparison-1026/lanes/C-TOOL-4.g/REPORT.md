# Lane C-TOOL-4.g — `bashSandbox: "allowlist"` + `sandboxAllowedDomains` wiring

## Increment 1 — machinery + settings + docs committed (slices 1–3)

- LANEPINCH: branch `cc/C-TOOL-4.g`, base/master 895b9d6fd (no drift at commit time).
- COMMIT 1: `685ffddb5d5e21bb498bb299c17fb2904d141ef7` — "C-TOOL-4.g: bashSandbox allowlist mode + sandboxAllowedDomains wiring", 16 files, author/committer Joe Huss <detain@interserver.net> (%ae/%ce separate greps = 1/1, zero Co-Authored trailers), porcelain 0 after.

### What landed
- `SandboxBackend::MODE_ALLOWLIST` + MODES gain (interface, per 4.c refactor — see drifts).
- `Bubblewrap`: allowlist props (allowlist/egressSocket/allowedDomains) through ctor/new/with*, fromSetting arm, mode(), probeKey egress leg, jailArgv egress block (socket bind AFTER the --tmpfs /tmp region, HTTP_PROXY/HTTPS_PROXY/http_proxy/https_proxy = http://127.0.0.1:50774, NO_PROXY=localhost,127.0.0.1, bridge tail `PHP_BINARY -r stub -- /tmp/.candy-egress.sock 50774 --` after the final `--`), describe() three allowlist arms (no-proxy ⇒ NO network at all; empty list ⇒ behaves exactly like no-network; granted list ⇒ 403 + non-HTTP-fails prose). JAIL_EGRESS_SOCKET='/tmp/.candy-egress.sock' (fixed short in-jail name; host scaffold path rides --bind source).
- `EgressBridge`: JAIL_TCP_PORT=50774 (single source for setenv URLs + run() bind), stub(autoload) one-liner requiring autoload + exit(runFromArgv($_SERVER['argv'])), runFromArgv() argv-contract validator (fail-closed: usage to STDERR, exit 2 WITHOUT running when the prefix is malformed).
- `Seatbelt`: allowlist reads as no-network (fail-closed); describe() gains the macOS sentence naming the loopback-port follow-up.
- `SandboxFactory::fromSetting()`: allowlist arm READS `LaunchContext::egressProxyInstance()` (never spawns — doctor probe shares this builder) → withEgress(socketPath, domains) when the shelf holds the launch proxy, withAllowedDomains(domains) fail-closed otherwise. forScope() inherits through the same builder (4.d jail reuse: hooks share the allowlist jail, no second path).
- `Bootstrap::egressProxy()`: resolve-once memo on the LaunchContext shelf; starts the 4.f `EgressProxyProcess` in the PARENT only when user-tier `bashSandbox === allowlist` and not Darwin; entries = `sandboxAllowedDomains` filtered to strings; throws propagate (loud-spawn law). `Bootstrap::tools()` calls it as its first statement.
- `LaunchContext`: shelf statics + egressProxyInstance/egressProxyResolved/markEgressProxyResolved/setEgressProxy/registerEgressProxyShutdown (pid-keyed shutdown twin of registerBackgroundShellShutdown)/stopEgressProxy (acts only on the owning pid) + useEgressProxyForTesting seam.
- Settings: `sandboxAllowedDomains` StringList [] user-tier Security withLayered (no settings validator by decision — malformed entries ignored at EgressPolicy enforcement, help/docs say so); LayeredSettings::LAYERED_KEYS via gen-settings-doc --write; LayeredSettingsTest:68 pinned roster flipped IN-STEP (derived==pinned green); README/SETTINGS generated rows regenerated; README hand-paragraph "never taken from a project file" → eighty-nine + `sandboxAllowedDomains` clause IN-STEP (TrustKeyDocumentationDriftTest); PERMISSIONS.md values table + "Domain allowlist" bullet.
- lang/en.php: settings.sandboxAllowedDomains.label/.help family-adjacent before sandboxScope rows; settings.bashSandbox.help rewritten to name allowlist + empty-list-is-no-network law up front.
- In-step census trio for the ONE new stderr emitter (`EgressBridge::runFromArgv` usage line, stderr-alone by the sibling-process decision): StderrEmitterCensusTest DIRECT roster row + :60 ledger word thirty-seven→thirty-eight + BinCandyCodeAutoloadGuardTest:35 THIRTY-SEVEN→THIRTY-EIGHT (precedent chain git log -L proven).

### Gates (cwd=candy-code, env -u LINES -u COLUMNS plain pipe)
- tests/Tools/Sandbox/ + tests/Tools/BashSandboxTest.php → OK 102T/514A.
- BinCandyCodeWiringTest → OK 1322T/7866A (Deprecations:2 = foreign AccessorDeprecation pattern, adjudicated).
- (SettingsSchemaDocDrift|LayeredSettings|CliLangCatalogue|TrustKeyDocumentationDrift|ConfigWriteProducerDocumentationDrift) → OK 153T/1460A.
- DocFigureProseDrift|ReadmeRosterDrift|StderrEmitterCensus|ReadPathCensus → OK 216T/28525A after the census trio flipped same-commit.
- BARE TreeWideGuardRosterTest → OK 17T/3920A; BARE DuplicatedTestHelperDriftTest → OK 14T/204A.
- gen-settings-doc --check → 'settings docs: up to date' rc0.
- 4.f zero-diff proof: `git show 685ffddb5 --stat` contains NO EgressPolicy.php/EgressProxy.php/EgressProxyProcess.php rows (machinery reused untouched); no PROC_OPEN added anywhere (spawn row stays 4.f's DIG-accounted ::start).

### Cite-drifts / deviations (recorded for the weld)
1. Design cites `Bubblewrap::MODES :53-56`; vocabulary lives on the SandboxBackend interface since 4.c — MODE_ALLOWLIST landed there (design-intent-faithful).
2. Design cites `Bootstrap::tools()` ":8523"; the door is in the :9529 region (:8523 is MCP constants).
3. `EgressBridge.php` touched beyond the design files list (JAIL_TCP_PORT/stub()/runFromArgv() = the single-source stub home the design's argv prefix implies); zero behavior change to existing members.
4. No settings validator for sandboxAllowedDomains (design's "refuse or skip-with-record" rides EgressPolicy::parseEntry's fail-closed drop — enforcement-side, never widens); ToolSettings row + help + PERMISSIONS state it.
5. en.php settings keys carry no sort guard (only the cli.* block is SORT_STRING-pinned); keys inserted family-adjacent.
6. LIVE through-jail leg is the only design test leg that can EXECUTE-gated here; argv/stub legs are EXEC-pinned via phpunit. If the live leg skip-gates on this host, the 200/403-through-proxy figure stays UNCONFIRMED and is labeled so below when slice 4 lands.

## Increment 2 — slice 4 + cures (COMPLETE)

Commit 2 = ed626d858a3f28524c0f12300991b2cd2b025f00 (cat-file: commit; law greps %ae=1 %ce=1
separately, Co-Authored=0; porcelain 0 after). 4 files, +490:
- candy-code/tests/Tools/Sandbox/EgressAllowlistModeTest.php (NEW — §7 LIST: 11 tests /
  90 assertions LIVE; gate `--filter EgressAllowlistModeTest` → OK 11T/90A, only the two
  known foreign Deprecations).
- candy-code/tests/Tools/BashSandboxTest.php — testTheSettingVocabulary gained exactly two
  arms (Bubblewrap 'allowlist'; Seatbelt reads 'no-network').
- candy-code/src/Cli/LaunchContext.php — resolve-once cure: setEgressProxy() now marks
  $egressProxyResolved (defect (h): repeated tools() calls would double-spawn).
- candy-code/src/Tools/Sandbox/EgressBridge.php — cure (i): public static resolveProgram()
  (execv does not search PATH; bare words in a wrapped command would exit 127 in
  allowlist jails — the allowlist may change only the network path; unfound words return
  unchanged so the honest 127 survives) + run() entry line + why-docblock.

Gates all EXEC green after cures: tests/Tools/Sandbox/ + tests/Tools/BashSandboxTest.php →
OK 113 tests / 606 assertions (was 102/514 — zero regression, +11T exactly the new file).
Bare DuplicatedTestHelperDriftTest OK 14T/204A; bare TreeWideGuardRosterTest OK 17T/3920A;
filter (DocFigureProseDrift|ReadmeRosterDrift|StderrEmitterCensus|ReadPathCensus) OK
216T/28525A; tools/check-one-type-per-file.php → 62 libs, 3358 files, 0 problems;
gen-settings-doc --check up to date (slice 3, re-verified).

REUSE / ZERO-DIFF PROOF (4.d + 4.f machinery untouched):
`git diff 685ffddb5^..ed626d858 -- candy-code/src/Tools/Sandbox/EgressPolicy.php
candy-code/src/Tools/Sandbox/EgressProxy.php candy-code/src/Tools/Sandbox/EgressProxyProcess.php`
= EMPTY (0 lines). No second policy engine, no EgressPolicy edit; entries ride the 4.f
ctor; the 4.d scope door flows allowlist jails through the SAME SandboxFactory::fromSetting.

LIVE-LEG STATUS: testTheLiveJailEgressRefusesOrRoundTrips runs a php driver subprocess
(4.f fork-in-subprocess law) wrapping REAL curl commands through a REAL Bubblewrap jail +
REAL EgressProxyProcess, hairpin fake-upstream on 216.158.226.14 (probed OK), denied arm
198.51.100.23 (TEST-NET-2, refused pre-DNS at granted() — hermetic). On THIS box the
JAIL-REFUSED arm EXECUTES (bwrap cannot start: loopback RTM_NEWADDR denied — documented
fail-closed family); the 200/403 round-trip arms are UNCONFIRMED-locally, capable-host
only — same both-arms-honest shape as BashSandboxTest::testTheRealBubblewrapConfinesOrRefuses,
never skip-only. Harness fact earned: this box's PHP 8.3.6 CLI parse-rejects argumentless
`exit 0;` — always parenthesize in generated code.

Deviation register (a)-(g) unchanged from Increment 1; (h) resolve-once defect+fix and
(i) resolveProgram cure added above. Design DoD met: argv pins, vocabulary pins
(both backends), proxy-starts-once-in-parent + stops-at-shelf pins (test 8, real child),
Seatbelt allowlist=no-network pin, settings cascade both-sides in-step, docs rows moved
with the machinery (commit 1), live leg host-gated per design.

