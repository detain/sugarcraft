# C-SEC-4.b weld — GATE RED, weld NOT closed (2026-10-27)

Picks + regen are ON MASTER (lead may reset or fix forward):
- 8f625725a = pick ee24cbcde (clean auto-merge, SERVER.md; no conflicts)
- 0fd50b209 = pick b8966c024
- 593e5d769 = regen trio+dist (schema.json, SERVER.md, generated.ts, dist) — gen-protocol-schema --write; npm run gen:protocol; npm run build; vue-tsc incremental 7s: TS2353 gone (regen fixed, as r2 predicted).
Authors: both greps %ae/%ce = detain both; zero trailers. git cherry master cc/C-SEC-4.b = "-","-".

RED: GatewayTrustRoutingTest::testAPromptMintedByAWorkspaceHostRoutesItsAnswerBackToThatHost —
assertSame 3-key result fails: actual carries extra `'root' => <child workspace root>`.
Deterministic 3/3 in main tree AND at the lane's own worktree tip (/home/sites/cc-cmp-C-SEC-4.b, b8966c024) — NOT weld-merge-induced; the lane's "OK 14T" gate claim does not reproduce on current disk.
Mechanism: Gateway::forward() annotates every forwarded stdClass result with
`$result->root ??= $root;` (candy-code/src/Server/Workspace/Gateway.php:375) — predates the lane
(only touched by rename commit e6567b98c, byte-identical base→tip). ContextMethods::trust returns
3 keys (master:105-110); the gateway adds root AFTER the child answers. Fix is lane-side:
either assert the 4-key shape (root is correct provenance for routed answers, cf. permission.respond
posture) or drop root in the trust result contract — lead ruling/fixer lane.

GREEN gates (main tree, cwd candy-code, plain pipe):
- --filter 'ContextTrustPrompt' → OK Tests: 12, Assertions: 74, Deprecations: 2, exit 0.
- --filter 'ProtocolSchemaDrift|ServerProtocolDocumentationDrift' → OK 12T/332A (regen consistent).
- vitest --no-file-parallelism 6 files (contextTrustRouting, statusBarTrustBadge, contextSources,
  contextPanel, reducer, statusBar) → 6 files / 57 tests passed; generated.spec.ts → 3 passed.
  (dormantRoster.spec.ts does not exist anywhere in the repo — N/A disclosed.)
- five-guard → Tests: 133, Assertions: 15,451, Failures: 2 = exactly the ReadmeSuiteFigureDrift
  staleness pair (25,537 vs live 25,793) — pair-only by design; re-pin is closeout duty.
- TreeWideGuardRoster|DuplicatedTestHelperDrift → 31T/3,488A/2F; both Fs = the disclosed
  pre-existing foreign Cli/SelfUpdateTest.php glob($phar.'.tmp-'.chr(42)) roster legs. NOT cured.
- TS2554 statusBar.spec.ts:160/:169 + reducer.spec.ts:307/312/317/320 reproduced EXACTLY at
  pre-weld base 3ee78848c in a temp worktree (2-arg .toBe(x,msg) calls in files outside the set;
  runtime green). Disclosed pre-existing; files identical base↔tip.

State left: lane IN FLIGHT — plan_comparison.md §4 NOT marked (file untouched), run-state.json
NOT edited, worktree + branch kept (prune withheld until fixer makes GatewayTrustRouting green).
Durations duty for closeout: NEW candy-code/tests/Server/GatewayTrustRoutingTest.php needs a
scripts/parallel-tests-durations.tsv row (listed only, not edited).
