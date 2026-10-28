# C-EXT-2.b — active skill effects honour allowed-tools, model and effort

DONE. Commit `8ef5643d86352b504d2afe6e5d0d2b85fca84c15` on `cc/C-EXT-2.b` (base ebf05989e), author/committer detain@interserver.net verified separately, zero Co-Authored trailers, exactly the step's 22 paths, no figure/durations/dist files, never pushed.

## Landed shape
- **src/Skills/ActiveSkillEffects.php** (new): pure, stateless. `derive(messages, registry)` slices the turn (from the newest real UserMessage — turn-context rows are not a boundary), merges `SkillTool::invokedIn` (most-recent-first) + `TurnContextBlock::invokedSkillsIn` roster, resolves through the registry (key → display-leaf), turns `allowed-tools`/`disallowed-tools` into `PermissionRule`s via `fromClaudePrefixDialect` (malformed entries skipped), takes the latest declaring skill's `model`/`effort`. `apply()` swaps the chain gate with the mode-switch `PermissionGateHook` re-register idiom — swaps grants in, clears stale ones, no swap when there is nothing to carry.
- **PermissionGate**: `withTurnGrants($allow,$deny)` clone (validates both lists); turn denials refuse *before the mode* (beats acceptEdits/bypass/dontAsk; a configured first-match answer stands; rm-rf breaker untouched); turn allow-lists answer Ask only, as-written per call — narrower than session grants (no earned-memo rewrite), never lift a configured Deny, never answer a forced security question. Carried across `withMode`.
- **Runtime::run()**: derives effects from the step's messages, swaps grants, rides `model`/`effort` onto the next `CompleteRequest` (skill declaration over the Task preset; documented). New user prompt clears everything.
- **Chat::gateToolCall()**: same `apply()` on the shared chain (tool grants only — Chat builds no request, so model/effort ride Runtime alone).
- **Effort sentinel**: `Skill::$effort` / `SkillFrontmatter::$effort` now `?string`, absent = null (no `'medium'` masquerading as a declaration); `SkillRegistry` stage-1 row follows.
- **FrontmatterKeyAudit**: the 4 SKILL INERT rows dropped (`context: fork` stays); docblock rewritten.
- **Docs in-step**: SKILLS.md field table rows now **Live** with honest Read-by cells, diagnostics narrowed to `context: fork` + unknown keys; README skills bullet flipped (pinned fork substring preserved).
- **Built-in trims (R-EXT-1)**: composer-wizard → `Read, Grep, Bash(composer:*)` (it drives composer through Bash; bare Bash and the nonexistent `Composer` name dropped); php-best-practices / phpunit-master / security-audit → `Read, Grep`; all four `effort:` lines dropped. Other four built-ins declared nothing.

## Gates (plain-pipe, cwd candy-code, linked vendor)
- tests/Skills dir: OK (580 tests, 2185 assertions)
- tests/Permissions dir: OK (1630→1704 with Runtime dir, 5715 assertions; ×2)
- tests/Runtime dir: OK (74 tests, 323 assertions)
- FrontmatterKeyAudit + InertFrontmatter drift guard + BootstrapLaunchNoticeRouting: OK (53 tests, 260 assertions)
- five-guard (`ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift`): 133 tests, exactly 2 failures = the expected suite-figure staleness pair (pinned 25,537 vs live 26,238 — whole-program, welder re-pin duty; files untouched by me).

## DUR rows for the welder (scripts/parallel-tests-durations.tsv)
- candy-code/tests/Skills/ActiveSkillEffectsTest.php — 0.080 (16 tests)
- candy-code/tests/Permissions/PermissionGateTurnGrantsTest.php — 0.206 (14 tests)
- candy-code/tests/Runtime/RuntimeSkillEffectsTest.php — 1.195 (5 tests)

## Deviations
1. BRIEF claimed all 8 built-ins declare `allowed-tools` + effort high; disk truth: only 4 declared (composer-wizard `Read,Grep,Bash,Composer` + effort medium; php-best-practices/phpunit-master/security-audit `Read,Grep,Bash` + effort high). Trims applied to those four.
2. `Composer` is not a candy-code tool name — dropped; composer-wizard's shell need is met by the scoped `Bash(composer:*)` per R-EXT-1's "no Bash pre-approval unless the skill runs shell".
3. Collateral in-step pins not in the brief's file list: `tests/Cli/BootstrapLaunchNoticeRoutingTest.php` (notice fixture/expectation, since the four keys left INERT) and `tests/Skills/SkillTest.php` (two default-effort pins → null).
4. Chat::gateToolCall applies tool grants only — model/effort have no seam there (no request builder); Runtime is the sole model/effort applier. Comment + docs say so.
5. `App::dispatchSkill()` still has no production caller; the model doc row says so honestly.
6. One mega-combined `--filter`-less run showed 4 pre-existing order/state failures (ChatGateToolCallSkillsTest + DontAskBypassTest) that never reproduced across five subsequent dir-shaped runs, including at base — disclosed, not cure-adjacent.
7. ActiveSkillEffectsTest final is 16 tests (earlier working notes said 14).

## Round 2 (fixer pass, 2026-10-27) — disposition: finding VOIDED, no commit

The assigned round-2 MINOR ("candy-code/src/Agents/Skills/ActiveSkillEffects.php ~:129 —
budget docblock names maxBytes while maxAllowed() counts shared per-root readBudget entries
incl. dedup/missing-child skips; re-land the lost one-sentence docblock cure") does not
describe this branch. Disk evidence at tip 8ef5643d8 (worktree clean):
1. `candy-code/src/Agents/Skills/` does not exist; the file is `candy-code/src/Skills/ActiveSkillEffects.php`.
2. That file contains ZERO tokens matching `maxBytes|budget|maxAllowed|readBudget` (grep); :120-135 is the allow/deny rule-merge loop.
3. `maxAllowed`/`readBudget` exist nowhere in candy-code src or tests (whole-tree grep; only vendor AWS SDK JSON blobs carry the substring `maxAllowedInvestmentDisbursementValue`).
4. REVIEW-2.md on disk rules the same way: fix commit 417385a64 never existed (no reset discarded content — the reflog shows only create@ebf05989e, two no-op `reset: moving to HEAD`, and the single 8ef5643d8 commit), and the described defect-shape belongs to untouched SkillPathNudge territory, not this lane's file set.
Re-landing the "cure" would have meant writing a false docblock sentence about budget logic into a class that has none — refused per doc-truth discipline. Behaviour unchanged; nothing executed, no executable or prose edit made.

Gates re-verified at 8ef5643d8 this pass (plain pipe, cwd candy-code, env -u LINES -u COLUMNS):
- `--filter 'Skill'`: OK — Tests: 999, Assertions: 6,951, 0F (2 pre-existing PHPUnit deprecations).
- `--filter 'PermissionGate|Runtime'`: OK — Tests: 955, Assertions: 10,900, 0F.
- five-guard: Tests: 133, Failures: 2 = exactly the staleness pair (pinned 25,537 vs live 26,238, :93/:120) — welder re-pin duty, unchanged from round 1.

Recommended orchestrator action: void the r1 MINOR for this lane (per REVIEW-2.md's own Disposition); the lane welds as the single commit 8ef5643d8.

## Round 2 addendum (second fixer pass, 2026-10-27, 23:5x) — verdict CONFIRMED: VOIDED, no commit

Independent re-verification at the same tip (branch `cc/C-EXT-2.b`, worktree clean, no new commits):
1. BOTH prior fix reports are phantoms: `git cat-file -t 417385a64` and `git cat-file -t c52a57e04` both fail ("Not a valid object name"); `git log --oneline -3` shows tip still 8ef5643d8 off ebf05989e.
2. The r1 MINOR's symbols are absent tree-wide: `grep -rn "MAX_SKILL_BYTES|readBudget|MAX_ALLOWED_ENTRIES|MAX_DISALLOWED_RULES" candy-code/ (--php/--md)` = 0 matches. (The house cure ffe7515da "C-CTX-1.c: review fixes (round 2)" edits `candy-code/src/Context/InstructionDiffTracker.php` — a different lane's file, untouched by this lane's 22-path diff; SkillPathNudge is not in the diff either.)
3. Gates re-run fresh (plain pipe, cwd candy-code, env -u LINES -u COLUMNS):
   - `--filter 'ActiveSkillEffects'`: OK — Tests: 17, Assertions: 45, 0F (2 pre-existing PHPUnit deprecations). (Brief's "60T-class" expectation not met by the live tree; actual lane figure is 17T for this filter — reported as measured.)
   - five-guard: Tests: 133, Assertions: 15,781, Failures: 2 = exactly the staleness pair (25,537 vs live 26,238, :93/:120) — byte-identical to the pass above and to REVIEW-2.
Per the brief's STOP clause (REVIEW-2 prescribes voiding/re-running r1, not a docblock cure), no code or doc edit was made on the branch — committing a fabricated docblock "fix" to lines that never claimed the alleged bound would repeat the phantom pattern. Action owed is orchestrator-side: void the r1 MINOR, weld the lane as the single commit 8ef5643d8, re-pin figures at closeout.
