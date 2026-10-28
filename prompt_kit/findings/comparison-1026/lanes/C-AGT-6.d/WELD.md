# WELD — C-AGT-6.d (`/pr` canned turn + `/review --pr <n>`)

- Lane: cc/C-AGT-6.d tip e040868c8b0cdd2d58f30e8951059b27389be402 (base cbe74fb5a, ONE commit, 20 files +1001/−21).
- Review r1: CLEAN 0C/0M/0 step-local minors (REVIEW-1.md; 2 NITs recorded only).
- Pick: **b6369af7869b565dea865497f3b8ba515f407eb0** — cherry-pick CLEAN, zero conflicts (auto-merge over P-TABS.f 0fb10ffbf / C-CTX-4.c d361720d9 touches to Chat.php/en.php/README/COMMANDS.md/DocFigure; both intents intact, no hand resolution).
- Author law: %ae 1/1 + %ce 1/1 detain@interserver.net (separate greps); %b zero trailers. Patch-id IDENTICAL both sides: 0766526f0cd75ea69d68b20319397c9b02cedb62 → content proof without conflict-resolution deltas.

## Merged-tree gates (plain pipe, env -u LINES -u COLUMNS, cwd candy-code; PtyRunToolTest never in filter)

- Primary (reviewer-spelled filter `PrCommandTest|GhRunnerTest|PrHostCommandTest|ReviewCommandTest|ContextMentionsTest|McpResourceMentionsTest|SlashDispatchTest|CommandDocsDriftTest`): **OK — Tests: 96, Assertions: 929** (EXACT vs REVIEW-1; +2 house PHPUnit deprecations).
- DescriptorInheritanceGuard: **OK — Tests: 15, Assertions: 5125**. Note: the lane's in-step `::diffRange` roster row CURES the inherited red left by C-AGT-6.b (base-copy harness proof in REPORT) — green at pick confirms the cure lands.
- Guards battery (ten REPORT names): **Tests: 275, Assertions: 46483, Failures: 2** — red set = ONLY ReadmeSuiteFigureDrift staleness pair (foreign-era standing red, closeout re-pin duty; NOT this lane). 275T EXACT vs reviewer's 275; assertions +132A vs their 46351 = foreign-era derivation wobble since their worktree cut (C-CTX-4.c settings-doc/DocFigure era), same red set.
- `php tools/gen-command-docs.php --check`: rc0 (COMMANDS.md/README rows ride the lane; CommandDocsDriftTest green inside primary battery).

## Riders — judged NOT owed

Pick file-set: zero `candy-code/src/Protocol/**`, zero `candy-code-web/**` (REVIEW-1 §2 confirms) → protocol-schema trio + web gen/dist riders NOT owed (MCP-3.a precedent).

## Durations rider

Separate commit **1c46570f2**: three rows per-file junit sums (single-file runs --log-junit /tmp, per-testcase column):
`candy-code/tests/Chat/PrCommandTest.php 0.019 6` · `candy-code/tests/Workspace/GhRunnerTest.php 0.238 6` · `candy-code/tests/Host/Commands/PrHostCommandTest.php 0.003 3`.
Descending-time/path-tie inserts verified; tsv 1519→1522, set-equality +3, zero duplicates. suite-figure.json + dist untouched (staleness pair = era closeout re-pin).

## NITs recorded (non-blocking, take-or-leave)

1. lang/en.php: `cmd.pr.description`/`cmd.pr.hint` sit INSIDE the `cmd.pruning.*` block (sorts before `cmd.pruning`, so canonical home is above `cmd.pruning.meaning.manual`). No key-order guard exists; cosmetic only.
2. The `gh could not read pull request …` (stderr-first-line) notice echoes host text via `shown()` (flatten+cap) not `framed()` — consistent with the file's existing notice-echo convention (session-name leg does the same); noted for the eventual notices-framing ruling.

## Carries

- en-only `cmd.pr.*` + `cmd.review.bad-pr` rows → 11-locale parity LAST per §6.3-h (closeout locale sweep).
- Observation (report-only, owner glance, from REVIEW-1): `/pr` drives `gh pr create` in-turn through Bash, whose scrubbed env strips `GITHUB_TOKEN`/`GH_*` — by R13 design (write gated by permission mode); env-token-only gh users get the honest in-turn failure the prompt names.

## After

git cherry proof `-` (patch-id equal) → worktree + branch pruned.
