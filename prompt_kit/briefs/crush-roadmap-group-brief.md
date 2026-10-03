# crush_report roadmap — group coordinator brief

You are the **coordinator for one group** of the sugar-crush roadmap execution plan
(`crush_report.md` Appendix R = `prompt_kit/findings/crush-report/17-execution-plan.md`).
Your prompt names your wave, group letter, worktree, steps (in order) and your row of the wave table.

## Read first (in this order, only what your steps need)
1. `prompt_kit/findings/crush-report/17-execution-plan.md` §1 (rules), your wave table row, §5 step index for your steps, §6 stale claims.
2. The impact rows for each of your steps: `prompt_kit/findings/crush-report/impact/<batch>.md` (batch map in §0 of the plan). They give exact files, method regions, new files, forced docs and drift tests.
3. The step definition: `prompt_kit/findings/crush-report/99-synthesis.md` Part III (0.x–5.x) / Part V, and the design appendix it points to (`13-settings-…` = N, `14-server-mode-…` = O, `16-sessions-…` = P, `03-opencode-dcp.md` §13.2 = DCP self-pruning). Competitor notes (`00`–`12`) only when the step cites them.
4. `CLAUDE.md`, `AGENTS.md`, `sugar-crush/CALIBER_LEARNINGS.md` for conventions.

Line anchors drift: **re-locate by method/symbol and verify the step is still open against current source before coding.** If a step turns out already done, say so with evidence and skip it.

## Where you work
- Worktree: `/home/sites/sugarcraft-wt/<wave>-<group>` on branch `fix/<wave>-<group>` (already created from `origin/master`, vendor trees copied, linked mode). Work ONLY there; anchor every command with absolute paths or `cd <worktree>/sugar-crush && …` (Bash CWD does not persist).
- Scratch (notes, logs, HANDOFF.md): `/home/sites/sugarcraft-wt/.scratch/<wave>-<group>`.
- TMPDIR: use the SHORT dir `/tmp/cr-<wave><group>` (e.g. `/tmp/cr-w2a`; `mkdir -p` it) in every test command — long TMPDIR paths overflow the 108-byte unix-socket path limit and make ~10 /bg, IPC, launch-notice and HOME tests go falsely red.
- Never touch `/home/sites/sugarcraft` (the main checkout) except to read the briefs/plan.

## Hard rules
- Do your steps **sequentially in the listed order**, yourself. Do NOT spawn sub-agents.
- **One commit per step**: `git -C <wt> commit` with message `sugar-crush: <step-id> <summary>` (body: what + why, short), author is the configured Joe Huss <detain@interserver.net>; end the message with
  `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`.
  Do NOT push, merge, rebase onto master, or open PRs. The integrator cherry-picks your branch.
- **Scope:** edit only your row's owned files, the step's new files + tests from its impact row, the named shared-doc sections, and `scripts/parallel-tests-durations.tsv`. In hotspot files (Chat, Runtime, EngineBackend, Bootstrap, Renderer, Sglang/CustomProvider, PermissionGate, WorkflowEngine, TaskTool, LayeredSettings, EnhancedSessionStore, Host/TurnRunner|TurnController|CompactionService) edit **only your named methods/regions** — the integrator rejects hunks outside them. If a step genuinely needs something else, do as much as fits in scope, then record the remainder in `/home/sites/sugarcraft-wt/.scratch/<wave>-<group>/HANDOFF.md` (what, which file/region, why) and continue.
- Single-owner resources (CS, R-KEYBIND, R-CLI, R-HOOKS, R-STATE, R-SCHEMA, TR, R-KEYS, R-CMDS, R-TOOLS) may be edited only if your row names them.
- Never `git stash` (shared across worktrees). Never `pkill -f`/`killall`. For hang-prone runs use a PID-scoped watchdog: `vendor/bin/phpunit X & P=$!; ( sleep 600; kill -9 $P 2>/dev/null ) & wait $P`.
- Skip Caliber entirely; if a hook stages Caliber-managed files (CLAUDE.md, AGENTS.md, .claude/, .cursor/, .opencode/, .agents/, .github/copilot-instructions.md), `git restore --staged` them.
- Do not edit `crush_report.md` or `prompt_kit/findings/crush-report/*` (integrator does). Never touch `sugar-crush/tests/Config/Support/suite-figure.json` or the README test-count headline.
- New test file ⇒ add a row to `scripts/parallel-tests-durations.tsv` (`sugar-crush/tests/…Test.php<TAB><est-seconds><TAB><test-count>`), keep the file's existing sort/format.
- Never stage files you did not change for your steps.

## Engineering rules (project standing decisions)
- `declare(strict_types=1);`, PSR-12, `final` classes, immutable `with*()` via `mutate()`, bare accessors, `::new()` factories, **one PSR-4 type per file** (`php tools/check-one-type-per-file.php`).
- **Wire dormant code, never delete it.** Fix root causes; never disable-and-TODO.
- Never add a total-request timeout on LLM calls (connect timeouts only).
- Docs are test-pinned: any new tool / slash command / env var / key binding / config key / CLI flag needs its README.md / docs/*.md edit in the same commit. Generated blocks (settings, commands, tools — once their generators exist) are regenerated with the generator, never hand-edited.
- `view()` never does side effects; TUI lines never exceed terminal width; WindowSizeMsg is size truth.
- Decision defaults D1–D12 in plan §1.6 are adopted (e.g. D1 frame vocabulary, D2 MCP toolTimeout opt-in, D5 TUI default `default`, D7 literal English labels, D10 Task cap 5).
- `proc_open` children must stay accounted for (`php tools/check-child-lifetimes.php`).

## Testing discipline
- Targeted only: your new/changed test files + the drift/census/golden tests your steps force (impact rows), by path or `--filter`. **No full suite.**
- Run independent files concurrently but capped: `printf '%s\n' <files> | xargs -P8 -I{} sh -c 'TMPDIR=/tmp/cr-<wave><group> vendor/bin/phpunit {} >"<scratch>/$(basename {}).log" 2>&1 || echo FAIL {}'` (from `<wt>/sugar-crush`).
- Always also run `tests/Config` and `tests/Commands` doc gates relevant to what you touched (e.g. `--filter 'ReadmeRosterDriftTest|EnvRosterDriftTest|KeyBindingDriftTest|DocFigureProseDriftTest|SymbolCitationDriftTest'`), `php tools/check-one-type-per-file.php`, and `php tools/check-child-lifetimes.php` from the worktree root.
- Cross-lib edits: run that lib's targeted tests too.
- A red you did not cause: confirm it by running the same test in the pristine main checkout (`cd /home/sites/sugarcraft/sugar-crush && vendor/bin/phpunit <file>` — read-only use) and report it; don't "fix" unrelated tests.
- Anything that genuinely needs a user decision or credentials you don't have: skip it, take the plan's documented default where one exists, and list it under HANDOFF.
- Every step must be green on its targeted set before its commit.

## Final report (your last message, ≤ 60 lines)
```
GROUP <wave>-<g>  branch fix/<wave>-<g>  commits: <n>
| step | status (done/partial/skipped-already-done/handoff) | commit sha | tests run → result |
Docs touched: …
Derived figures the integrator must re-measure (counts, census, citations, generators): …
HANDOFF.md items (verbatim summary): …
Known reds not caused by this group: …
```
