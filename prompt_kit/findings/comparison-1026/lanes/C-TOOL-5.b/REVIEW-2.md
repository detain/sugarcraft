# REVIEW-2 — C-TOOL-5.b (default formatter table `formatAuto`) — 2026-10-28

VERDICT: CLEAN (0C/0M/0 step-local minors)

Worktree /home/sites/cc-cmp-C-TOOL-5.b, merge-base(master,HEAD)=4b771634d, single commit
fb441bbf7. %ae and %ce both detain@interserver.net (separate greps, 1 each), zero
Co-Authored/Signed-Off trailers, tree clean after all gates.

1. Scope: 14 files = the declared set + the two disclosed in-step edits
   (LayeredSettings::userTierOnlyKeys +1 'formatAuto', inside the declared region;
   DocFigureProseDriftTest widen + the same-commit format-row regex that matches the
   lane's own HOOKS.md bullet) + LayeredSettingsTest roster pin (test twin of the hub
   edit). suite-figure.json / durations.tsv / dist provably untouched.
2. Gates, cwd candy-code, plain pipe: FormatAutoTable|FormatRunner 38T/208A OK;
   PostEditFormat 15T/70A OK; LayeredSettings 109T/427A OK; DocFigureProseDrift
   91T/3970A OK; SettingsSchemaDocDrift|TrustKey|EnvRoster|ReadmeRoster|SettingsDocDrift|
   ConfigWriteProducer 93T OK; LangParity 7T OK.
3. Widen minimal: needle 'new PermissionGateHook(' measured at ~9477-9501 bytes from
   'function hooks(' → 9000 structurally red, value on disk is exactly 12000.
4. Non-vacuity traced: Bootstrap::hooks() real readUserConfig → strict ===true bit →
   withAuto(bit AND projectHooksAreTrusted($root)) → $hooks->register into the live
   HookManager; launch tests resolve the instance via HookManager::hook() and assert
   runner() state; the e2e test spawns a planted node_modules/.bin/prettier through the
   real format() path. Neuter of the commandFor auto loop, the fold, or the register
   door each reddens named pins. Detection is a stat walk (no subprocess, no roster row).
5. lang: 2 new keys, en-only (parity strictness is an open §6.3 call; LangParity green).
   Docs/README word-numbers moved in-step (130/74/72), generator regions consistent —
   the doc-drift guard family above judges them.

Inherited, NOT lane findings (weld visibility): the lane tree shows StderrEmitterCensus
×3 (NonInteractive.php 14→15, ArgvParser.php 35→36, raw-fwrite 33→34) and
TreeWideGuardRoster ×2 (JsonSchemaEnvelopeTest, MemoryRecallBlockTest) reds — all name
files outside the lane's 14-file set; they are base-reds at 4b771634d already cured on
current master (green at tip 533ab1431 in the main tree). The cherry-pick weld onto tip
carries the cures; suite-figure staleness pair remains merge-owned as disclosed.
