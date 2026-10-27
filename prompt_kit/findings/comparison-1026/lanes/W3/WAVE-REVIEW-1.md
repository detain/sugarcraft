# W3 wave review — round 1 (wave-reviewer, 2026-10-27, master 9530b27a4 era)

VERDICT: FINDINGS (report write blocked by reviewer sandbox — persisted verbatim by the lead)

## Findings
[MAJOR] candy-code/tests/TreeWideGuardRosterTest.php:1094+:1951 red pair — dispatch allowed-list mislabels it foreign: the `glob($phar.'.tmp-'.chr(42))` walker site was ADDED by W3's own a8ead9976 (C-SES-5.b, testTheSidecarSignatureAsset… in SelfUpdateTest) without the same-commit roster move — §1.8 in-step guard law violated; the C-SES-2.b "inherited at base 50ec2e702" claim inherited W3's earlier weld. Fix: one master commit adding `'Cli/SelfUpdateTest.php'` keyed on `glob($phar.'.tmp-'.\chr(42))` to WALKS_A_DIRECTORY_THE_TEST_MADE (walks a test-created dir); no in-flight collision.

[MAJOR] candy-code/tests/Support/ReadPathCensusTest.php:1165 'Background/BackgroundShellRegistry.php|scandir' expected 2 actual 4 — foreign-attributed to C-TOOL-7.a (87dba649c +147 LOC there, census not updated); not W3, not in allowed-red set; route to that lane's wave loop: re-derive the classification (scandir×2 sites now) in the same file.

## Clean
10/10 W3 steps ✅ in §4 ∧ in run-state 'welded', all ancestors, A=C=detain, zero trailers; §4 file-lists match `git show --stat` (C-SEC-3.b narrowing disclosed in its own ✅ annotation; C-SEC-4.b full chain incl regen 593e5d769+fixes). Generators gen-command/tool/settings-doc + gen-protocol-schema --check all rc0; five-guard only staleness pair (25,537 vs 26,024); allowed reds reproduced exactly (RemoteBackend 1F, AttachPaging 2F, EnvRoster CANDY_CODE_REF×2); Bootstrap|Launch 1072T OK, Rule|Frontmatter 909T OK, SessionPicker family 163T OK, census trio 110T OK, McpCall/PRV-1.c unions green; web vitest green (contextTrust/statusBar/contextSources 9T; generated+contextPanel 14T; dormantRoster correctly absent pre-C-WEB-3.c); zero W3 worktree/branch residue; E646/fork laws hold (child pins via RunsWallClockBoundedChildTrait).

## Carries (not findings)
durations rows owed at closeout for McpCallHookParityTest/LaunchEffortNoticeTest/GatewayTrustRoutingTest/SessionPickerDebouncedSearchTest; C-SEC-3.b opt-in→C-SEC-3.c; SessionListAction.php:44 doc row; suite-figure re-pin. Wave NOT clean → no ✅W3.

---
LEAD NOTES (post-review, same day):
- MAJOR-2 disposition: ALREADY IN FLIGHT — cc/cure-roster fixer (ses_e81cac344) is re-arming ReadPathCensusTest with the two NAMES_ONLY verdicts for BackgroundShellRegistry.php:548/:568 per lanes/C-TOOL-7.a/FINDING-readpath-census.md. Cures land via welder picks.
- MAJOR-1 disposition: cure #2 dispatched — add 'Cli/SelfUpdateTest.php' row to WALKS_A_DIRECTORY_THE_TEST_MADE in a single master-repair commit. Attribution note: the pair WAS carried as "disclosed foreign" since the a8ead9976 era; the wave review correctly re-classifies it as chain-internal §1.8 debt. Both legs at :1617/:1635 are the same two call sites already reproduced.
- Re-review (round 2) once both cures are welded, then wave-close W3 (§3/§4 marks + waves_closed += 3).
