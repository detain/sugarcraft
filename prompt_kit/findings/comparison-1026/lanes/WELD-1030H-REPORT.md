# WELD-1030H — C-TUI-2.f + C-SES-7.d (sequential, this order)

## PLANNED (written before any mutation)
- Anchor: master == 1af0cc89012fe296caeb00fab10b2545cc432db8 (verified by git rev-parse, this session).
- Weld 1: cc/C-TUI-2.f (tip e8b17987070fe927e9fffed41c118b6381ffc406, base dc9eea92d) — cherry-pick all master..branch commits; dist rebuild via `cd candy-code-web && npm run build` (local node_modules/.bin, no npm ci/composer); NO durations rows; NO protocol trio regen (verify via git show --name-only); gate = vitest --no-file-parallelism + local vue-tsc; §4 mark with figure line +38 vitest (14/19/11); DOC-ROW (README.md:241 lazy->eager DiffView) + cycleDiffStyle caller-less recorded as closeout, NOT edited now.
- Weld 2: cc/C-SES-7.d (tip 04741cb7dbc5b60bbfc1da0789819d6d80e0dbd0, picks 9195b8bcc/719b80e34/3a7b8c511/db117a763/04741cb7d) — cherry-pick 5; r1 CRITICAL cure at src/Chat.php ~:20693 EngineBackend gate + ctor-TAIL param must land intact; mint 2 durations.tsv rows (ResumeCostEstimateTest + ResumeCostGuardTest, median of 3 per-file junit runs each); +21T figure = §7 carry, DO NOT re-pin; MANDATORY ARCHITECTURE.md dispatch-arms numeral re-derivation with landed-blob proof `git show HEAD:candy-code/docs/ARCHITECTURE.md | sed -n '150,160p'`; gates = ResumeCostEstimate|ResumeCostGuard + 6-file casualty cluster, always exclude PtyRunToolTest expect-miss arm; gen-*-doc --check zero-drift.
- Env-var check both lanes (Help.php roster law).
- §4 marks committed with each weld's carry files. Run-state re-read FRESH after each weld, welded_count 248->249->250, assert == len(welded), master_tip = second-to-last commit.
- Prune only after git cherry '-' proofs + landed-blob cure checks; collect worktree-only evidence before pruning.
- NO PUSH. NO pull/merge/force.

## STATUS LOG (append-only, SHAs cat-filed)
- [x] anchor verified 1af0cc89012fe296caeb00fab10b2545cc432db8 (git rev-parse master)
- [x] name-only audit: C-TUI-2.f touches ONLY candy-code-web/src-web/** + lanes/C-TUI-2.f/FIX-1.md (rides pick e8b179870) — NO src/Protocol, NO MethodSchemas -> protocol-trio regen NOT needed (verified, as required). No phpunit files -> no durations rows (verified). No getenv/process.env in web diff (Help-roster law: N/A).
- [x] C-SES-7.d name-only audit: README/SETTINGS.md/lang/en.php/Chat/LayeredSettings/InterfaceSettings/UiSettings/ApplySettingsTest/2 new test files. New phpunit FILES: tests/Host/ResumeCostEstimateTest.php (branch path — brief said Chat/, actual Host/) + tests/Chat/ResumeCostGuardTest.php -> 2 durations rows owed. No getenv/Help.php touches (law N/A). lang/en.php + README region collision risk = append-keep-both; SETTINGS.md = regen if drifted, no hand-merge.
- [x] weld 1 cherry-picks landed, ZERO conflicts (no candy-code-web commits on master since base dc9eea92d): a34b878e4 -> 0ec7baa10da24390babf4106aba83906fba585f9; e8b179870 -> 093cd25e1ddbbb589a32c2edeaa033518a4815d4 (both cat-file -t commit).
- [ ] dist rebuild (npm run build, local .bin only)
