VERDICT: CLEAN — 0C/0M/0 step-local minors (reviewer lane read-only; see notes)

# C-WEB-2.a step review r1 — 2026-10-30
Lane /home/sites/cc-cmp-C-WEB-2.a, branch cc/C-WEB-2.a, tip 48ee2233a.
`git log master..HEAD` = exactly one commit (48ee2233a). merge-base HEAD master = **671105299** — NOT 740c29868 as the spawn brief said; the builder's REPORT.md itself truthfully cites 671105299. Lane is cut two welds (CI-1030 62abc32ef + sequencer 740c29868) behind the brief's stated base. Consequence is weld-side only (see handoff). Tree clean, worktree registered, author %ae/%ce detain@interserver.net 1/1, zero Co-Authored trailers.

## 1. Traceability / scope — PASS
Diff touches exactly the three files the block names:
- `candy-code/src/Host/SessionHost.php` — edits confined to `runCommand()` + its docblock (the declared region). Same-namespace resolution (`SugarCraft\Code\Host`) makes `TurnController::`/`SubmitOptions::` legal without new use statements — verified both files declare that namespace.
- `candy-code/docs/SERVER.md` — one prose line at :688 inside the command.exec paragraph; zero `<!-- protocol:` markers in lines 680–695 (outside generated regions, §2 soft-doc rule honored).
- `candy-code/tests/Host/BtwMidTurnTest.php` — new, matches files.create.
No suite-figure/durations/dist/README touches. No stubs/TODOs.

## 2. Correctness — PASS (hand-traced)
- Gate: `midTurnRoute($text, $isWorkflowControl($text), SubmitOptions::new())` admits only ROUTE_WORKFLOW_CONTROL + ROUTE_SIDE_QUESTION; QUIT/REFUSE_COMMAND/QUEUE/STEER/INTERRUPT all keep the `hostCommandNotice` refusal — exactly the brief's fix. `/exit` mid-turn stays refused through this door (identical to old behavior; disclosed).
- The `$options` comment is code-true: for '/'-prefixed text the table returns before the final `match ($options->effectiveDelivery())`, so `SubmitOptions::new()` cannot change the verdict (runCommand's text is always '/'+name).
- Red-before reasoning is deterministic: base code `if ($this->isBusy() && !$this->isWorkflowControl($text))` refuses `/btw` → test1 admission assert fails; test2 refusal pin passes pre- and post-fix (behavior-preserving proof for other commands). M2 (`if (false)`) → runCommand('clear') dispatches, test2 refusal assert at :123 fails. Reviewer harness is read-only bash (no cp/ln allowed by the agent permission map), so these two were verified by code reading, not re-executed; the builder's REPORT claims match the tree's logic.

## 3. Production reachability — PASS (traced end-to-end)
web `session.ts send()` (built-in branch, ~:165-184) → `command.exec` → `CommandMethods::exec` (CommandRegistry name match 'btw') → `runBuiltIn` → `host->runCommand('btw',$args)` → admitted → `dispatchCommand` → `BtwHostCommand` (3500-btw.php carries `withHostCommand(BtwHostCommand::class)` → `runsIn: server`, so no client-side -32030 and no `isClientOnly` throw). Not dormant: the web was already calling this door; the diff opens it. Test drives the real runCommand/submit/drain/settle/save path (no fabricated success), and pins the block's risk: UI-only question row + answer row + turn reply all survive settle AND reappear in `loadTranscript('s')`; side call carries `BtwHostCommand::PROMPT` on the title backend; refusal pin asserts history byte-unchanged and turn still held.

## 4. Gates re-measured (cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)
- `--filter BtwMidTurnTest`: **Tests: 2, Assertions: 19** OK (2 PHPUnit deprecations, tree-baseline: identical count in every filter incl. the pre-existing trio).
- `--filter ServerProtocolDocumentationDriftTest`: **6/59** OK.
- wire set `CommandMethodsMcpPromptsTest|AttachRemoteCommandsTest|ConformanceTest|ServedProjectCommandTrustTest`: **38/256** OK.
- guard filter `GlobDialect|DuplicatedTestHelperDrift|DocFigureProseDrift|ReadmeRosterDrift|CommandDocsDrift`: combined-substring run **136/4839** OK; per-exact-class sum Glob 13/366 + Dup 14/203 + DocFigure 92/4178 + ReadmeRoster 10/78 + CommandDocs 6/11 = **135T/4836A** — builder's figure is the exact-class sum, confirmed.
- No full suite run (targeted-only law); PtyRun hazard filter not used.
- Protocol trio: grep `command_refused` in `docs/protocol/*.json` + `candy-code-web/src-web/protocol/generated.ts` = **zero hits** (rc=1) → builder's "no trio regen owed" VERIFIED; no src-web change → no dist rebuild owed.

## 5. Conventions / project laws — PASS
declare(strict_types=1) first; final class; test literals not glob-shaped and not `**Refused:**`-shaped (guards green); one type per file (anonymous Backend doubles inline-legal); no new proc_open/fork sites; E646 untouched (drain 15s guard is a test time-limit, not a request timeout); no doc-claim lies found (docblock wording matches the code).

## 6. Plan §6 — nothing decided differently for this step. Deviations: none claimed, none found.

## Handoff to welder
1. **MINT durations.tsv row** for `candy-code/tests/Host/BtwMidTurnTest.php` (lane measured ~10.4s wall for 2T/19A; file is new — unlisted = zero shards).
2. Cherry-pick 48ee2233a onto CURRENT master (base lane lacks CI-1030 + sequencer + later commits; brief said 740c29868, actual merge-base 671105299) and re-run `--filter BtwMidTurnTest` + guard family + ServerProtocolDocumentationDrift on the merged tree (UsageRecordsTest reds at older bases are cured on master — do not attribute to this lane).
3. Suite-figure re-pin carries +2T/+19A live-enum from this lane (§7 duty).
4. Mark §4 ✅ <weld-sha> C-WEB-2.a.
