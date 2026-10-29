# W18 wave-loop review 3 — tip reviewed: e3ccc13bf

VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR remaining for W18's own account (MAJOR-2 residual lives on W21/W22's account, enumerated below; must not be absorbed by W18's close).

## Cures verified (task 1)
- 5406bce93 (MAJOR-1, ReadPathCensus): single-file tests-only (+3/-3). Collapses the two ToolIpcFiles rows into ONE takeIpcPayload() row naming the helper. Reality check: Chat.php has exactly 3 file_get_contents sinks at tip — :6140 takeIpcPayload, :13901 presentsAsPrimary (f51949469's correct row kept), :17671 pastedImagePath — and the roster holds exactly those 3 classified rows. Census MATCHES reality; not a roster-blinded pass (batch-16 44ff31314's double-label mis-cure is gone, no stale row).
- 003cb1e4e (MAJOR-3, ToolCapSettings): single-file tests-only (+10/-2). Adds `WEB_SEARCH_BACKEND_KEY => SearchBackends::BACKEND_SEARXNG` at KEYS order (ToolLimits.php:98/:175 confirms key+slot; SearchBackends:32 confirms default spelling) and mirrors the endpoint's Egress exclusion in the risk-pin loop.
- Authors: both %ae and %ce = detain@interserver.net (separate greps), zero Co-Authored/signed trailers; both ancestors of HEAD.
- Gate re-run by reviewer (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe): `--filter 'ReadPathCensus|ToolCapSettings'` → **OK, 50 tests / 948 assertions** (fixer's 937A era drift, tree-scan re-count; deprecations=2 are the suite-boot baseline pair, present in every filter incl. untouched SettingsWriter — not cure-introduced). MAJOR-1 + MAJOR-3 CLOSED.

## MAJOR-2 foreign attribution (task 2)
- ReadPathCensus HALF is already satisfied at tip (all three Bundles rows carry verdicts; present already at 86f80fef8 — r1's "no verdict" wording reflected the count leg of the one red diff, since cured). Green above proves it.
- SettingsWriterCensusTest:145 IS STILL RED at tip, exactly 2 unclassified producers in the diff:
  - `Cli/Bootstrap.php::stampBundleRecommendations->write` — introduced by 0273a6178 = C-EXT-4.d = **W21** step (plan:157).
  - `Chat.php::persistFast->write` — introduced by 0da9b5071 = C-PRV-3.b = **W22** step (plan:158; rode weld-18 batch per plan:2716 but plan-assigned, not a W18 step).
- Neither is among W18's 10 steps; the roster file was last touched outside W18's picks (empty git log for SettingsWriterCensusTest in 86f80fef8..HEAD on the cure side). W18 CLEAN does not absorb this: cure rides W21's route per the lead's routing (both rows).

## Whole-wave coherence (task 3)
- 10/10 W18 marks are master ancestors: 5e30f5e94 (c4fb3580f pick), 21732353d, cc3be741c, ca69cb333, a03990a0f, c8e8a1f42, 83ef5e0a2, aa0e46158+ff32fbd87 (master-side of lane 963ddd0f6/2e32c90bf), fc38985ab. Unchanged from r1.
- Generators rc0 x4 at tip: gen-settings-doc / gen-command-docs / gen-tool-docs / gen-protocol-schema (all "up to date").
- durations.tsv rows present for every W18 new phpunit file (PrimaryAgentSwitch/ServiceTier/Keychain/ReadBeforeWrite/BomPreservation/DiffReview/CheckpointDiff+Files/MessagePicker/RecapHostCommand/FirstRunWizard/ProviderKeyStore). No wave commit touched suite-figure.json.
- r1 MINOR-4 cured: branch cc/C-AGT-4.b gone, worktree /home/sites/cc-cmp-C-AGT-4.b pruned (worktree list clean of it), lanes/C-AGT-4.b/REPORT.md now in the main tree.

## What remains for W18 to CLOSE (task 4)
1. This CLEAN verdict + run-state/§4 wave-close bookkeeping (welded-count, tip stamp) — lead/welder duty.
2. NIT-5 (r1): COMPARISON.md cells MOD-N2 :154 / MOD-25 :152 / :64 / :936 — program CLOSEOUT duty via apply_comparison.py, not W18-blocking.
3. NIT-6 (r1): plan-text erratum for C-TOOL-5.a — closeout ledger stamp.
4. NOT W18's: SettingsWriterCensusTest:145 2-row roster cure (W21 route covers stampBundleRecommendations; the persistFast row needs the same W21/W22 fixer commit — flag the lead it is a W22-owned sink riding the W21 route).

Reviewer tip at verdict: e3ccc13bf.
