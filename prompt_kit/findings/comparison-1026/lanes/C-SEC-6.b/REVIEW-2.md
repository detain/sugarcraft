# C-SEC-6.b — REVIEW-2 (round 2, fresh reviewer)

VERDICT: CLEAN

Scope: 403742811 on top of cbe7d05df (base e91eb1d47). Fix commit = 3 files (+51/-2): docs/SETTINGS.md, src/Cli/Bootstrap.php, tests/Cli/BootstrapProjectNarrowingRulesTest.php — all inside declared ownership (serialized regions cover the permissionRules call site; plan §6:2859 declares C-SEC-6.b's Bootstrap claims as permissionGate + permissionRules). Author/committer detain@interserver.net both greps, zero Co-Authored; no suite-figure/durations/dist touch.

MINOR-1 cured on the code route, real and sound. agentManager() feed (:2629) now `[...self::projectNarrowingRules(), ...$subAgentUserRules]` — structurally identical to the main-gate merge at :7227 (first-match-wins ordering verified in r1). Pin `testTheSubAgentGateCarriesTheProjectNarrowingAheadOfTheUserRules` drives the REAL Bootstrap::agentManager() + createSubAgent('tester') and asserts BOTH legs: child rule ORDER [['Bash','deny'],['Bash','allow']] and the Deny decision. Mutation-reasoned: user-rules-only feed gives shape [['Bash','allow']] and evaluate→Allow — both legs red, exactly this test; the fixer's 9T/1F claim holds. Child-wider-than-session is closed on both gate paths.
Notice-dedupe claim verified true: warnPermissionConfigInTranscript = LaunchNotices::record (in_array message-identity guard, dedupe-before-overflow-count) + warnPermissionConfigOnce (per-process reported-set); the notice string is deterministic (file/count/dropped), so a launch building both gates says it once per channel. Signature keyed-set is likewise idempotent.
WebFetch.php:635 disposition accepted: outside the step's file set (editing there would itself be a scope MAJOR), and it is a distinct live-re-reading rules-only redirect-veto surface; the doc claims r1 cited (README ~:1299, PERMISSIONS.md:611) speak to the GATE, which is now unqualified-true. Legitimate weld-carry follow-up.

MINOR-2 cured: SETTINGS.md boundary paragraph gains exactly the prescribed clause (shared file, trusted root, deny/ask only, allow dropped-and-reported, never through the LayeredSettings stack, trust stays user-tier) and its PERMISSIONS.md#the-four-trustedproject-keys anchor resolves (PERMISSIONS.md:1201, same form as the pre-existing :148 link).

Gates run myself (cwd=candy-code, -c phpunit.xml, plain pipe, -u LINES -u COLUMNS): php -l clean on both touched PHP files; --filter 'BootstrapProjectNarrowingRules|Permission|BootstrapLayeredSettings|StderrEmitterCensus|TreeWideGuardRoster' → Tests: 2468, Assertions: 39023, Failures: 2 — exactly the known foreign pair (PermissionsIntroDriftTest:59 base-red, ForeignAgentPresetWiringTest:472 base-inherited); lane class 9T/26A OK; 2 deprecations config-level pre-existing.

NIT-3 (PermissionsCommand tier mislabel) and NIT-4 (lang help row under-informs) — weld-carry doc material per r1, correctly untouched.
