# crush_code — CONCURRENCY MAP

**What this file is for.** The `sugar-crush` audit plan (`crush_code.md`, 75 items) is executed by a
supervisor who spawns agents. Until now that has been **one agent at a time in the live tree at
`/home/sites/sugarcraft`**. The user wants to be able to flip that to **N agents in N isolated copies
of the repo, all on `master`, each committing and pushing to `master`** — and to flip back — depending
on their session limits at the time.

This file is the thing you read to make that switch without re-deriving anything. It carries: the
operational procedure both directions, the per-item lane assignment with the measurements behind it,
the honest parallelism ceiling, the directory recipe, and the rules that stop lanes colliding.

**Read `docs/plans/crush_code_RESUME.md` first** — it is the plan's entry point and this file assumes
its §3 (sequencing), §5 (the recurring defect), §6 (forbidden files), §7 (sandbox), §8 (test facts) and
§9 (the plan misstates its own state). This file supersedes RESUME §7 in exactly one place, called out
in the recipe below.

**Measurement discipline in this file.** Every count names what it counted. Anything I did not measure
says **not measured**. Where a grep disagreed with `crush_code.md` or with RESUME, the grep is written
down as the finding and the plan's claim is quoted beside it. That is not pedantry — it is this
project's single dominant twenty-six-round defect (RESUME §5: *a number or a claim that travelled
without its domain*), and a concurrency map is exactly the kind of document that spreads one.

---

## 0. THE HEADLINE, so you do not have to read the rest to make the decision

- **5 lanes are defined. The sustainable concurrency is 3. The hard ceiling is 4.**
- **The ceiling is set by `src/Cli/Bootstrap.php`, not by the machine.** Measured: **11 of the 27
  remaining plan items must edit `src/Cli/Bootstrap.php`** — 41% of the queue, including the two
  largest remaining items (Phase 2 item 9, the plugin system; Phase 6 item 2, layered settings). Those
  11 are a single strictly-serial lane. No lane design shortens it.
- **`src/Chat.php` is NOT the bottleneck for the remaining plan items** — only 2 of 27 certainly edit
  it. It *is* the bottleneck for the hardening backlog: **12 of the 50 `E`-items name `src/Chat.php`
  as their `Where`.** So the shape of the conflict graph changes completely when the queue moves from
  features to hardening. Re-read §3 at that transition.
- **Host capacity is not a constraint and must not be used as a reason to cap N.** 48 cores, 218 GB RAM
  available, 1008 GB disk free, load 4.57 (supervisor-measured 2026-08-19). Six full-repo lanes cost
  ~11 GB of 1008 GB and six single-process phpunit runs against 48 cores. If you cap N, cap it for
  conflict reasons or for the user's own session/API limits — and say which.
- **Isolation granularity: copy the WHOLE repo (`cp -a sugarcraft <lane>`), not just `sugar-crush/`.**
  Measured, and this is the strongest single argument in the file: in a whole-repo copy all 16
  `sugar-crush/vendor/sugarcraft/*` symlinks resolve *inside the copy* with **zero** repointing, the
  copy is a real git repo that can push, and a lane can safely edit a sibling lib. See §5.
- **`git worktree` is the wrong tool here** and I verified the refusal by hand rather than quoting it:
  `git worktree add /tmp/wt-probe-master master` → `fatal: 'master' is already used by worktree at
  '/home/sites/sugarcraft'`. The user wants everyone on `master`. See §5.4.

---

## 1. MODE SWITCH — read this to go from 1 agent to N, or back

### 1.1 Preconditions that hold in BOTH modes

- The live tree `/home/sites/sugarcraft` is the **supervisor's** tree. It is never a lane. In N-agent
  mode the supervisor still verifies and still owns the shared prose (§6.4).
- Everything in `docs/plans/crush_agent_rules.md` still applies to every agent in every mode. Hand
  agents that **path**, never an inlined copy — RESUME §"AGENT CONTEXT BUDGET" records that a ~6,000
  word inlined preamble is one of the four causes of an agent hitting 360k context.
- RESUME §6's forbidden-files list still applies **inside every lane copy**: `sugar-crush/phpunit.xml`,
  `.sugar-crush/config.json` (md5 must stay `05480c743aff302fd6c06c5a4a4c2210` — verified unchanged
  2026-08-19), `docs/plans/plans_cleaning.md`, `sugar-crush/python_port/`,
  `docs/plans/crush_code_worklog.md`, `docs/plans/crush_code_RESUME.md`.
  **Correction to RESUME §6, measured:** it calls `plans_cleaning.md` and `python_port/` "the user's own
  *untracked* work". Both are **git-tracked** (`git ls-files` returns them). The don't-touch rule stands;
  the stated reason is wrong, and it matters — an agent could reason "untracked ⇒ invisible to
  `git status` ⇒ harmless to edit", and the opposite is true: an edit there lands in that lane's diff
  and then in a commit to master.

### 1.2 TURNING CONCURRENCY **ON**

Run these from the supervisor's tree. Total wall cost for 3 lanes: under a minute.

```sh
# 1. The live tree must be clean and current. A dirty tree is copied into every lane.
cd /home/sites/sugarcraft
git status --porcelain            # must be EMPTY. If not: commit or drain first (§1.4).
git rev-parse --abbrev-ref HEAD   # must be: master
git pull --ff-only                # CI pushes to master on its own — see §6.3.

# 2. Create one full-repo copy per lane. NEVER `cp -al` (see §5.2).
for lane in w u s; do
  cp -a /home/sites/sugarcraft /home/sites/crush-lane-$lane
done

# 3. Verify EVERY lane before handing it to an agent. Do not skip this — §5.3 is
#    the check that proves the sandbox is real, and three agents have been burned
#    by a sandbox that silently reached back into the live tree.
for lane in w u s; do bash /home/sites/sugarcraft/docs/plans/.lane-verify.sh /home/sites/crush-lane-$lane; done
#    (…or run §5.3's four commands by hand — the script is not shipped, §5.3 is the source of truth.)
```

Then spawn: each lane's agents get **its own absolute path** as their repo root, and are told
explicitly that `/home/sites/sugarcraft` is off-limits to them. That last sentence is not optional —
every brief and every rules file in this project currently hardcodes `/home/sites/sugarcraft`, and an
agent that pattern-matches on those paths will edit the supervisor's tree from inside a lane.

**The one-line change to every agent brief in N-agent mode:**

> Your repo root is `/home/sites/crush-lane-<x>`. Every absolute path in
> `docs/plans/crush_agent_rules.md` that begins `/home/sites/sugarcraft` means
> `/home/sites/crush-lane-<x>` for you. You must not read or write anything under
> `/home/sites/sugarcraft`. Verify before your first edit: `pwd -P` and
> `git -C /home/sites/crush-lane-<x> rev-parse --show-toplevel` must both be your lane.

### 1.3 TURNING CONCURRENCY **OFF** (the ordinary case: nothing in flight)

```sh
cd /home/sites/sugarcraft
git pull --ff-only                        # picks up every lane's pushed commits
git status --porcelain                    # must be empty
vendor/bin/phpunit  # (from sugar-crush/) — the supervisor's own verification run
rm -rf /home/sites/crush-lane-w /home/sites/crush-lane-u /home/sites/crush-lane-s
```

### 1.4 DRAINING AN IN-FLIGHT LANE (the case that actually needs a procedure)

The user says "drop back to one agent" while lane U is mid-item. There are two kinds of work in that
lane and they drain differently.

**(a) Work the lane already committed and pushed.** Nothing to do — it is on `origin/master`. The
supervisor's `git pull --ff-only` collects it.

**(b) Work the lane committed but has NOT pushed.** Fetch it from the lane by path — the copies are
independent repos, so a lane directory is a perfectly good git remote:

```sh
cd /home/sites/sugarcraft
git fetch /home/sites/crush-lane-u master:refs/heads/drain-u
git log --oneline master..drain-u          # inspect what you are about to take
git merge --ff-only drain-u  ||  git cherry-pick <sha>…   # ff-only if it is a clean descendant
git branch -D drain-u
```

**(c) Uncommitted work in the lane's working tree.** Export it as a patch that includes untracked
files (`add -N` is what makes a new file appear in `git diff`), then apply it in the live tree:

```sh
git -C /home/sites/crush-lane-u add -N .           # untracked files become visible to `diff`
git -C /home/sites/crush-lane-u diff > /tmp/drain-u.patch
wc -l /tmp/drain-u.patch                            # sanity: a 0-line patch means nothing was pending
git apply --3way /tmp/drain-u.patch
```

Then **verify before deleting the lane**: run the full suite in the live tree, confirm
`Skipped: 1` and `rc 0`, and only then `rm -rf` the lane. A drained patch that does not apply cleanly
is a *stop*, not a puzzle to solve at speed — keep the lane directory until it is resolved. Deleting a
lane is the only irreversible step in this whole document.

**Never drain by copying files over the top of the live tree.** `cp -a <lane>/sugar-crush/src
/home/sites/sugarcraft/sugar-crush/` silently reverts anything the live tree gained since the lane was
made, and the lane was made from a snapshot.

### 1.5 SWITCHING BACK ON LATER

Lanes are cheap and disposable. Do **not** try to keep a lane directory alive across an off/on cycle —
it will be stale by exactly the amount that makes the next rebase interesting. Delete it, and make a
fresh `cp -a` from a clean, pulled `master`. That is 7-10 seconds (§5.1).

---

## 2. THE LANE TABLE

### 2.1 What the columns mean

- **id** — `P<phase>.<item>` in `crush_code.md`. The plan's own numbering.
- **BS?** — does the item have to edit `sugar-crush/src/Cli/Bootstrap.php`? (the primary bottleneck)
- **Chat?** — does it have to edit `sugar-crush/src/Chat.php`?
- **+src?** — does it add a `src/**.php` file, i.e. does it move the five censuses (§6.5)?
- **size** — S/M/L. **This column is an estimate, not a measurement.** It is my judgement from the
  item's file fan-out and text; treat it as a hint for batching, never as a schedule.
- **blocked-by** — hard dependencies only: a symbol that must exist, or a seam that must be wired
  first. "Feels later" is not in this column.

### 2.2 IN FLIGHT — do not schedule these

Three bundles are mid-workflow in the live tree's working directory as of 2026-08-19 (`git status`
shows `src/Cli/Subcommands.php` and `tests/Cli/BootstrapConfigPathOverrideTest.php` untracked, plus 13
modified files). They map to two plan items:

| bundle | plan item | primary files | BS? | Chat? | +src? |
|---|---|---|---|---|---|
| C5a | P4.6 (part) | `src/Cli/ArgvParser.php`, `src/Cli/NonInteractive.php`, `src/Cli/Bootstrap.php` | ✅ | — | — |
| C5b | P4.6 (part) | `src/Cli/Subcommands.php` (new), `bin/sugarcrush`, `src/Cli/Help.php` | ✅ | — | ✅ |
| C4a | P2.4 (part 1) | `src/Chat.php`, `src/Commands/CommandLoader.php`, `src/Cli/Bootstrap.php` | ✅ | ✅ | — |

**All three are lane-W-and-U work.** Concurrency cannot be switched on cleanly until they are committed
— §1.2 step 1 requires a clean tree. Commit them first, or drain them per §1.4(c) into a lane.

**A free measurement, since I ran the suite in a copy of the dirty tree to validate the sandbox recipe
(§5.3):** with all three in-flight bundles present, the suite is **7,741 tests / 90,040 assertions /
1 skipped / rc 0**, 3m11s. HEAD (`1c78cc7a`) is 7,637 / 89,596 / 1. So the in-flight work is **green**
and adds +104 tests / +444 assertions. That is a supervisor-run figure, not an agent's report — but it
was taken in a sandbox copy, not the live tree, so **re-run it in the live tree before committing**;
RESUME's rule that the baseline moves every round applies to this number as much as any other.

### 2.3 THE 27 REMAINING ITEMS

`crush_code.md`'s status block says **48 of 75 done, 27 left**, and I verified the arithmetic against
its own phase sections: Phase 0 = 14 done, Phase 1 = 3 done, Phase 2 = 6 of 9, Phase 3 = 1 of 5,
Phase 4 = 6 of 7, Phase 5 = 10 of 10, Phase 6 = 0 of 6, Phase 7 = 2 of 6, Phase 8 = 6 of 15. Remaining
by phase: 2→3, 3→4, 4→1, 6→6, 7→4, 8→9 = **27**. ✅

| id | intent (one line) | primary files (measured) | lane | blocked-by | size | BS? | Chat? | +src? |
|---|---|---|---|---|---|---|---|---|
| **P2.4** | `CommandLoader` into `Chat` + `$ARGUMENTS`/`$1..$9`, then `` !`cmd` `` (ReactPHP `Process`) + `@file` | `src/Chat.php`, `src/Commands/CommandLoader.php`, `src/Cli/Bootstrap.php` | **W** | part 1 (C4a) in flight; part 2 (C4b) needs part 1's instance seam | L | ✅ | ✅ | — |
| **P2.7** | write `LspTool implements Tool` over the built `src/LSP/LspClient.php`, append to `Bootstrap::tools()` | new `src/Tools/BuiltIn/LspTool.php`, `src/Cli/Bootstrap.php:3277-3316` | **W** | nothing | M | ✅ | — | ✅ |
| **P2.9** | unified `crush-plugin.json` + `PluginLoader`; directory-convention auto-discovery | new `src/Plugins/*`, `src/Cli/Bootstrap.php`, probably `src/Chat.php` (plugin commands into the slash menu) | **W** | plan says "once items 1-7 land"; the *real* prerequisite is P2.4 (a plugin ships `commands/`, and there is no file-command seam until P2.4 wires one) and P2.7 | L | ✅ | ⚠ likely | ✅ |
| **P3.2** | swap `Tui\Pane`'s hand-rolled `next()` for `candy-focus\FocusRing` (Shift-Tab for free) | `src/Tui/Pane.php`, `src/Tui/KeyboardHandler.php:343`, `composer.json` | **S** | **`sugarcraft/candy-focus` is NOT a dependency — see §2.4. Supervisor must add it first.** | S | — | — | — |
| **P3.3** | `sugar-veil` `withClickOutsideDismiss()` on the palette / session-picker / permission overlays | `src/Chat.php:3478 handleMouse()`, `src/Renderer.php:1150` (the `Veil::new()` site) | **U** | nothing (`sugarcraft/sugar-veil` already required; `Veil::clickOutsideDismiss()` already exists at `sugar-veil/src/Veil.php:161`) | M | — | ✅ | — |
| **P3.4** | adopt `candy-sprinkles\Table` for `/sessions`, `/agents`, MCP list, LSP diagnostics output | `src/Chat.php` (the `implode("\n", $lines)` sites at ~5410, 5842, 6025, 7810, 7885, 7919, 8037) | **U** | LSP-diagnostics slice needs P2.7; the other three do not — **land it incrementally, as the item itself says** | M | — | ✅ | — |
| **P3.5** | (a) cell-width-aware padding in `SplitLayout.php:238` / `AgentViewPane.php:112`; (b) restyle `Cli\Help::screen()` with `candy-kit` | (a) `src/Tui/SplitLayout.php`, `src/Tui/AgentViewPane.php` → **S**; (b) `src/Cli/Help.php` → **W** | **split S+W** | (b) **`sugarcraft/candy-kit` is NOT a dependency — §2.4** | S | (b) — | — | — |
| **P4.6** | subcommands, `--config`, exit codes, `--output-format` validation | see §2.2 | **W** | **IN FLIGHT** | M | ✅ | — | ✅ |
| **P6.1** | `WorktreeConfig`'s project-config path is `__DIR__`-relative, should be `$root`-relative | `src/Agents/WorktreeConfig.php:106` | **C** | nothing | S | — | — | — |
| **P6.2** | layered settings: `.sugar-crush/settings.json` + `.local.json` + `~/.sugar-crush/settings.json`, merged | new `src/Config/*`, `src/Cli/Bootstrap.php` (it owns every config read: `readUserConfig`, `writeUserConfig`, `userConfigPath`, `configDirPath`, `trustedConfigDirPath`, `permissionConfig`, `permissionRules`) | **W** | nothing, and **it is the prerequisite for P6.3/P6.4/P6.5/P6.6** | L | ✅ | — | ✅ |
| **P6.3** | `tools.allow`/`tools.deny` filter over `Bootstrap::tools()`'s returned list | `src/Cli/Bootstrap.php` | **W** | **P6.2** — there is no merged-settings object to read the keys off until it exists | S | ✅ | — | — |
| **P6.4** | a `permission`/`permissionMode` settings block | `src/Cli/Bootstrap.php` (`permissionConfig()` ~2491, `permissionRules()` ~2659) | **W** | **P6.2**; the plan's other stated prerequisite (Phase 1 item 2, `PermissionGate` in the main loop) **is already satisfied** — Phase 1 is complete | M | ✅ | — | — |
| **P6.5** | `keybindings` remap + `statusLine` command config | `src/Commands/KeyBindingRegistry.php`, `src/Tui/KeyboardHandler.php`, `src/Renderer.php`, `src/Cli/Bootstrap.php` (the config read) | **W** | **P6.2** for the config half. The plan says this "can land independently at any point" — **that is true of the TUI half only**; the config half cannot precede the settings loader | M | ✅ | — | — |
| **P6.6** | `--model` / `--permission-mode` CLI flags as the top precedence tier | `src/Cli/ArgvParser.php` (grep: **zero** `--model`/`--permission-mode` tokens today), `bin/sugarcrush`, `src/Cli/Bootstrap.php` | **W** | **P6.2** — "highest-precedence override tier" is meaningless without the tiers below it | M | ✅ | — | — |
| **P7.3** | `docs/SKILLS.md`, `docs/AGENTS_AUTHORING.md`, `docs/MCP.md`, `docs/PERMISSIONS.md` | new files under `sugar-crush/docs/` | **D** | describes shipped behaviour — genuinely unblocked | M | — | — | — |
| **P7.4** | `docs/HOOKS.md`, `docs/MEMORY.md`, `docs/WORKFLOWS.md`; fix README's built-in-hooks list (omits `BashEscapeDenyHook`) | new files under `sugar-crush/docs/`, `sugar-crush/README.md` | **D** | nothing | M | — | — | — |
| **P7.5** | `docs/TROUBLESHOOTING.md` from `CALIBER_LEARNINGS.md`'s solved incidents | new file under `sugar-crush/docs/` | **D** | nothing | S | — | — | — |
| **P7.6** | `docs/ARCHITECTURE.md`, incl. the "`App` wears two hats, do not retire it" warning | new file under `sugar-crush/docs/` | **D** | nothing | M | — | — | — |
| **P8.3** | `StallDetector` → the agent dashboard | `src/Tui/Components/AgentDashboardPane.php` (`agentEntry()` ~343, `sessionEntry()` ~369) | **S** | nothing — **and the plan is wrong about which half is missing; see §2.5** | S | — | ⚠ read-only | — |
| **P8.4** | decide the split-pane compositor's fate: wire it, or document it as deferred | `src/Tui/SplitLayout.php`, `src/Tui/MultiplexerSplitPane.php`, `src/Tui/Renderer.php` | **S** | wiring needs `AgentManager::liveOutput()` — which **exists** (cited in `AgentDashboardPane.php:357`), so the item is a decision, not a blocked task | M | — | — | — |
| **P8.6** | record VHS demos for the permission modal, an Edit/Write diff, the agent dashboard | `sugar-crush/.vhs/*.tape` | **D** | nothing — **and it is very probably already DONE; see §2.5** | S | — | — | — |
| **P8.8** | a PHP-feasible repo-map block injected once per session like `<env>` | new `src/Context/RepoMap.php` (name mine), `src/Context/EnvironmentBlock.php` or `src/Runtime.php:1424` | **C** | nothing | M | — | — | ✅ |
| **P8.9** | give `Grep` the `InstructionFileLoader` wiring `Read`/`Edit`/`Glob` already have | `src/Tools/BuiltIn/Grep.php` (measured: **0** `InstructionFileLoader` hits; ctor is `(?string $root, int $maxOutputBytes)`), `src/Cli/Bootstrap.php` (the `new Grep($root)` line) | **W** | nothing | S | ✅ | — | — |
| **P8.10** | a size-capped `git diff` in `EnvironmentBlock` alongside status/log | `src/Context/EnvironmentBlock.php` (measured: **0** `git diff` hits) | **C** | nothing | S | — | — | — |
| **P8.11** | `loadRoot()` monorepo-parent awareness (or document the gap) | `src/Context/InstructionFileLoader.php` (`loadRoot()` :150, `loadForPath()` :330) | **C** | nothing. Related to backlog **E26** (multi-root `PathJail`) but not blocked on it — this item is about reading *above* `$root`, E26 is about *jailing* multiple roots | M | — | — | — |
| **P8.13** | expose sub-agent spawning as a model-callable `Task` tool | new `src/Tools/BuiltIn/Task.php`, `src/Cli/Bootstrap.php:3277-3316` | **W** | plan says "depends on Phase 1's `AgentManager` wiring" — **Phase 1 is complete**, so it is unblocked. Real prerequisite: a decision on how the sub-agent's tool-call stream surfaces through `ToolStarted`/`ToolFinished` | L | ✅ | ⚠ likely | ✅ |
| **P8.15** | note-only: no file-watching / no "this file changed since you read it" signal | `docs/plans/crush_code_hardening_backlog.md` (as a new `E`-item) | **D** | nothing. The item explicitly proposes no fix | S | — | — | — |

### 2.4 THE BLOCKER NEITHER `crush_code.md` NOR RESUME MENTIONS

**`sugarcraft/candy-focus` and `sugarcraft/candy-kit` are not dependencies of `sugar-crush`.** Measured:
`sugar-crush/composer.json` requires `candy-sprinkles` and `sugar-veil` among the SugarCraft siblings;
`sugar-crush/vendor/sugarcraft/` holds **16** entries and neither `candy-focus` nor `candy-kit` is one
of them. Both libs exist in the monorepo (`candy-focus/src/FocusRing.php`, `candy-kit/src/Banner.php`
/`HelpText.php`/`Logo.php`).

So **P3.2 and P3.5(b) cannot be executed by an agent under the current rules**, because closing the gap
means a `require` bump *plus* `composer update 'sugarcraft/*'` — and `composer install`/`update` is on
every agent's forbidden list (it replaces the `vendor/sugarcraft/*` symlinks with Packagist copies; the
only signal is the skip count going 1 → 2 and every figure the agent reports after that is void).

**The supervisor must do this personally, once per lane that needs it, before handing the item over:**

```sh
cd /home/sites/crush-lane-s
#  add the require line to sugar-crush/composer.json by hand, then:
php tools/check-path-repos.php --fix --strict-closure     # scratch only, never committed
cd sugar-crush && composer update 'sugarcraft/*' --quiet  # sugarcraft/* scoped: 3rd-party stays put
cd .. && git checkout -- '*/composer.json'                # throw the injected repos[] away
#  now re-add ONLY the require line (the checkout above reverted it too), then:
php tools/check-path-repos.php --no-lib-path-repos        # must exit 0
ls -la sugar-crush/vendor/sugarcraft/ | wc -l             # 16 → 17
cd sugar-crush && vendor/bin/phpunit tests/Tools/BuiltIn/GitignoreAwarenessTest.php
#  the symlink-awareness test must PASS (not skip) — a skip means the symlinks are gone
```

This also makes `sugar-crush/composer.json` a **cross-lane shared file** (§6.4).

### 2.5 THREE ITEMS WHERE MY GREP DISAGREES WITH THE PLAN

RESUME §9 is titled "the plan lies about its own state — verify, don't trust". Three more, measured
2026-08-19. **Each must be re-measured by the bundle's own measure step before any code is written** —
that is the rule B4 established (a queue row is not evidence), and I am a queue row.

**(a) P8.3 — the plan has the two halves backwards.**
`crush_code.md`'s own inline note says *"CALL-SITE HALF DONE, render branch outstanding … Nothing
paints it — zero `stall` hits in `src/Renderer.php`."* Measured:

| what | measured |
|---|---|
| `stall` (case-insensitive) in `src/Renderer.php` | **0** — the plan's number is correct |
| `stall` in `src/Tui/AgentOutputPane.php` | **the render branch, fully written**: `:58 $isStalled = $state->stallWarning !== null;`, `:43 STALL_TOKEN = 'shellWarning'`, and its docblock at `:20-21` — *"the border switches to the palette's warning colour and a `⚠ stalled` indicator appears"* |
| is that painter reached? | **yes** — `AgentDashboardPane.php:237` calls `AgentOutputPane::render(...)` |
| callers of `BackgroundSupervisor::getStallWarnings()` | **0** outside its own definition |
| does anything pass `stallWarning:`? | **no** — `AgentDashboardPane::agentEntry()` (:343) and `sessionEntry()` (:369) both call `AgentOutputState::fromDisplayState(...)` without it, and the parameter defaults to `null` |

So the render branch is **done**, and what is missing is the hand-off from
`BackgroundSupervisor::getStallWarnings()` into those two `fromDisplayState()` calls. `stallWarning` is
permanently `null`, which makes an existing painted branch unreachable in production.
**The plan's "zero stall hits in `src/Renderer.php`" is RESUME §5 in its purest form**: a count
measured on `src/Renderer.php` (the transcript renderer) written as a property of the agent dashboard,
which is painted by `src/Tui/`. Same defect, in the tracker, about the defect-prone file.

**(b) P8.6 — its premise is false at HEAD.**
The item says *"currently only one tape exists (plain markdown reply)"* and asks for three: the
permission modal, an Edit/Write diff, the agent dashboard. Measured, `sugar-crush/.vhs/` contains
**5 tapes and 5 gifs**: `agents`, `chat`, `cli`, `diff`, `permission` — i.e. **exactly the three the
item asks for, plus two**. `sugar-crush/examples/` carries their drivers: `permission-prompt.php`,
`edit-diff.php`, `agent-dashboard.php`. `permission.tape`'s own header comments explain the `n`-answer
choice. And `origin/master` is one commit ahead of local with `2f51b1a3 "vhs: regenerate demo GIFs"`,
which re-rendered all five. **Expect P8.6 to close as a no-op like B4 did** — but confirm by opening
the three tapes, because "a tape exists" is not "the tape shows what the item asked for".

**(c) P6.1 — "largely already fixed" is half right, and the half that matters is not fixed.**
RESUME's bundle table says *"item 1's `__DIR__` bug is largely already fixed; verify before writing."*
Measured: `src/Agents/WorktreeConfig.php:106` is still
`public static function defaultConfigDir(): string { return \dirname(__DIR__, 3); }` — i.e. still
`__DIR__`-relative, which is the exact defect the item names. What *was* fixed is testability: the
docblock at `:94-104` says the expression became "a named seam" specifically so the test can drive a
temporary tree instead of mutating the repo's own tracked `.sugar-crush/config.json`, and it says in as
many words that *"changing where a shipped library reads its settings from is not this change-set's
call"* — a deliberate deferral, not a fix. So the item is **open**, and the previous work is a reason
it is now cheap rather than a reason to skip it.

### 2.6 LANE TOTALS

| lane | owns (primary) | items | in flight |
|---|---|---|---|
| **W** — wiring / CLI / config | `src/Cli/**`, `bin/sugarcrush`, `src/Commands/CommandLoader.php`, new `src/Tools/BuiltIn/*`, new `src/Plugins/*`, new `src/Config/*` | P2.4, P2.7, P2.9, P3.5b, P4.6, P6.2, P6.3, P6.4, P6.5, P6.6, P8.9, P8.13 | C5a, C5b, C4a |
| **U** — transcript / input | `src/Chat.php`, `src/Renderer.php` | P3.3, P3.4 | (C4a also touches `Chat.php` — see §3.3) |
| **S** — shell / panes | `src/Tui/**` | P3.2, P3.5a, P8.3, P8.4 | — |
| **C** — context / instruction loading | `src/Context/**`, `src/Agents/WorktreeConfig.php` | P6.1, P8.8, P8.10, P8.11 | — |
| **D** — docs / media | `sugar-crush/docs/**`, `sugar-crush/.vhs/**`, `sugar-crush/examples/**` | P7.3, P7.4, P7.5, P7.6, P8.6, P8.15 | — |

Item counts: W = 11 distinct plan items (12 rows, because P3.5 is split), U = 2, S = 4 (incl. P3.5a),
C = 4, D = 6. **11 + 2 + 4 + 4 + 6 = 27.** ✅

---

## 3. WHAT MAY RUN CONCURRENTLY

I am deliberately giving a short list of statements I can defend rather than a 27×27 matrix. A matrix
would be exhaustive and unread; these five statements are what actually governs the decision.

### 3.1 The safe set — run these at the same time, in separate lane directories

> **W + S + C + D may all run concurrently. Their primary file sets are disjoint, measured.**

- W touches `src/Cli/**`, `bin/`, and new files under `src/Tools/BuiltIn/`, `src/Plugins/`,
  `src/Config/`.
- S touches `src/Tui/**` only.
- C touches `src/Context/**` and `src/Agents/WorktreeConfig.php` only.
- D touches `sugar-crush/docs/**`, `.vhs/**`, `examples/**` only.

No file appears in two of those four sets. **The three carve-outs that break it are §3.2, §3.4 and
§6.5** — read those before trusting this line.

### 3.2 U is the exception, and it is an exception to W not to S/C/D

**U cannot run while W holds P2.4 or P2.9, because both of those edit `src/Chat.php` too.** P2.4 is in
flight right now as C4a. U's own two items (P3.3, P3.4) both edit `src/Chat.php` as their primary.

Concretely: **do not open lane U until C4a/C4b are committed.** After that, U runs alongside W freely
until W reaches P2.9 — at which point either U is drained first, or P2.9's `Chat.php` slice is handed to
U as a sub-item and W does only the `Bootstrap.php` half. I recommend the latter and I recommend
deciding it *when P2.9 is briefed*, not now: whether P2.9 needs `Chat.php` at all is marked ⚠ likely in
the table because I inferred it from the item text ("merging file-based commands into what
`Chat::slashMenuMatches()` searches" is P2.4's language, and a plugin ships `commands/`), **not from a
grep — there is no `PluginLoader` in `src/` to grep.** Measure it in P2.9's measure step.

### 3.3 W is strictly serial *inside itself*, and that is the whole ceiling

All 11 of W's items edit `src/Cli/Bootstrap.php` (212 KB, and C3 moved it by ~486 lines in one bundle).
Two concurrent W agents would conflict on essentially every item. **W is one agent at a time, always,
in both modes.**

Worse, W's items are also serially *dependent*: **P6.3, P6.4, P6.5 and P6.6 are all blocked on P6.2**,
because each of them reads a key off a merged-settings object that P6.2 creates. That is 4 of W's 11
items sitting behind one L-sized item. P6.2 is therefore **the single highest-leverage unblock in the
entire remaining queue** and should be W's next item after the in-flight bundles land.

### 3.4 Sibling-lib edits are the conflict that lane isolation does NOT catch by construction

sugar-crush-scoped lane ownership says nothing about `candy-core/`, `candy-vt/`, `candy-shine/`,
`candy-vcr/`. Those get edited: commit `6c1e51c8` is titled *"sugar-crush + candy-core"* and `fe7ce954`
is *"sugar-crush + candy-core + candy-vt"* — two of the last five commits touched siblings. And the
hardening backlog has four sibling-lib items: **E15** (`candy-vcr/src/Tape/Lexer.php`), **E46**
(`candy-core/src/Util/Width.php`), **E49** (`candy-shine/src/Renderer.php`), **E50**
(`candy-core/src/SgrState.php`).

In a full-repo lane each lane edits *its own copy* of `candy-core`, so nothing breaks until push — and
then two lanes' `candy-core` commits rebase against each other with no lane-ownership rule to separate
them.

> **The rule: a sibling lib is claimed by NAME, by one lane at a time, from the supervisor.** Before a
> lane may edit `candy-*`/`sugar-*`/`honey-*` outside `sugar-crush/`, it asks. The supervisor keeps the
> claim list — there are only ever a handful. A lane that finds it needs an unclaimed sibling edit
> mid-item **stops and reports** rather than taking it; RESUME §3's "record the finding, defer the fix"
> discipline already covers exactly this case (E46 and E49 are both "W1 was scoped to sugar-crush, so
> this was deferred").

### 3.5 The concurrency arithmetic, stated honestly

- **11 of 27 remaining items (41%) funnel through `src/Cli/Bootstrap.php`.** That set includes the two
  largest remaining items and a 4-deep dependency chain behind P6.2.
- The other 16 items spread over 4 lanes: U = 2, S = 4, C = 4, D = 6, and 12 of those 16 are S or M.
- So: **during the early rounds, 4 lanes have work and the sustainable N is 3-4.** As U/S/C/D drain —
  and they will drain much faster than W, because W holds the big items — **N falls to 1**, and the
  tail of this plan is lane W alone regardless of what mode the user is in.
- **Wall-clock is W-bound.** Concurrency here buys you the 16 non-W items running "for free" alongside
  W. It does not shorten the plan by 3×, and any schedule that assumes it does is wrong.
- **The one thing that would genuinely raise the ceiling** is splitting `src/Cli/Bootstrap.php`. It is
  212 KB and 4,000+ lines, it owns tool registration *and* config reading *and* permission config *and*
  MCP lifecycle *and* the shutdown seam. That is not a concurrency task and it is not in the plan, so I
  am naming it and not proposing it — but if the user ever asks "why can't we go faster", this is the
  answer.

### 3.6 When the queue reaches the hardening backlog, this whole map inverts

Measured over `docs/plans/crush_code_hardening_backlog.md`'s 50 `E`-entries, by their own `**Where**`
lines:

| primary file | E-items | count |
|---|---|---|
| `sugar-crush/src/Chat.php` | E2, E3, E4, E7, E8, E17, E20, E21, E22, E31, E32, E33 | **12** |
| `sugar-crush/src/Renderer.php` | E5, E6, E43, E47, E48 | **5** |
| `sugar-crush/src/Context/ContextCompactor.php` | E18, E23, E38 | **3** |
| `sugar-crush/src/Cli/Bootstrap.php` | E40, E45 | **2** |
| sibling libs | E15, E46, E49, E50 | **4** |
| everything else (one file each, or no single file) | E1, E9-E14, E16, E19, E24-E30, E34-E37, E39, E41, E42, E44 | 24 |

So in the hardening phase **lane U is the bottleneck (17 of 50 items across `Chat.php` + `Renderer.php`)
and lane W nearly empties.** The lane *definitions* survive the transition; the *staffing* must flip.
Do not carry a feature-phase lane sizing into the hardening phase.

---

## 4. PRIORITY ORDER

Per RESUME §3: **functionality first, hardening last**, with frame-corruption, automatic data loss and
confirmed RCE counting as functionality and jumping the queue whenever a user reports one.

**Tier 0 — clear the decks (must happen before concurrency can be switched on at all)**
1. Commit C5a, C5b, C4a. §1.2 step 1 needs a clean tree.

**Tier 1 — the unblock, and it is a single item**
2. **P6.2 (layered settings)** — lane W. It unblocks P6.3, P6.4, P6.5, P6.6. Nothing else in the queue
   unblocks four items.

**Tier 2 — runs concurrently with Tier 1, in its own lanes**
3. Lane D: P7.3, P7.4, P7.5, P7.6, P8.15 — zero code files, zero shared code, and P7's docs describe
   already-shipped behaviour. This is the safest lane in the plan and should be running whenever N > 1.
4. Lane D: **P8.6 first, actually** — because §2.5(b) says it is probably already done, and a 20-minute
   confirmation that closes an item beats a 3-hour bundle that reimplements one.
5. Lane C: P6.1, P8.10, P8.11, then P8.8 (P8.8 last in C because it is the census-mover — §6.5).
6. Lane S: P8.3 (small, and §2.5(a) means it is smaller than the plan thinks), P8.4, P3.5a. P3.2 only
   after the supervisor has added `candy-focus` (§2.4).

**Tier 3 — lane W, after P6.2, in this order and for these reasons**
7. P6.3, P6.4, P6.6 — the settings consumers; small, and they make P6.2 real rather than decorative.
8. P6.5 — bigger, and its TUI half reaches into `Renderer.php`, so **coordinate with lane U** or take
   it while U is idle.
9. P8.9 (Grep instruction wiring) and P2.7 (LspTool) — both are `tools()`-array edits; do them
   back-to-back so one census update covers the pair if they land in one commit.
10. P2.4 part 2 (C4b) — `` !`cmd` `` + `@file`.
11. P8.13 (Task tool) — needs the sub-agent-stream decision made first.
12. P2.9 (plugin system) — **explicitly last of the features**, per the plan's own note, and per §3.2
    it wants lane U drained.

**Tier 4 — the standalone**
13. **`#88` / backlog `E1`, the stale README suite figure.** RESUME is emphatic that this goes in a
    standalone commit with **nothing else in flight**, because the figure has been invalidated three
    times in one session. In N-agent mode that means: **it is the last thing done before switching
    concurrency off, or the first thing done after** — never while a lane is live.
    Measured 2026-08-19: `sugar-crush/README.md:629` says "7,276 tests / 76,239 assertions"; HEAD's
    baseline is 7,637 / 89,596. `README.md:651` says "all 10 built-in" tools — that one is **live** and
    moves with P2.7/P8.13. `README.md:649`'s "4,337/12,587" is a **deliberate historical citation**
    ("For scale rather than for accuracy…") and **must not be touched**.
    Note the three documents cite three different line numbers for the same figure — RESUME says
    `:531`, backlog E1 says `:396`, the file says `:629`. Take the file.

**Tier 5 — hardening, E1-E50.** Re-read §3.6 before staffing it.

---

## 5. DIRECTORY SETUP RECIPE

### 5.1 The decision: full-repo copy

**Recommendation: `cp -a /home/sites/sugarcraft /home/sites/crush-lane-<x>` — the whole monorepo, not
just `sugar-crush/`.** Four reasons, all measured 2026-08-19:

| | full-repo `cp -a sugarcraft` | lib-only `cp -a sugar-crush` |
|---|---|---|
| `vendor/sugarcraft/*` symlinks | **all 16 resolve inside the copy, zero repointing.** Verified: `readlink -f <copy>/sugar-crush/vendor/sugarcraft/candy-core` → `<copy>/candy-core`; `find -xtype l` over that dir → **0** broken | **all 16 escape the sandbox.** They are relative `../../../<lib>/`, which from `<lane>/vendor/sugarcraft/` resolves to `<parent-of-lane>/<lib>` — the wrong place, or the live tree. This is RESUME §7's repointing chore *and* an isolation leak |
| git | **a real repo.** Verified in the copy: branch `master`, HEAD `1c78cc7a`, `origin git@github.com:detain/sugarcraft.git`. It can commit and push with no extra wiring | **no `.git` at all** — `sugar-crush/` is a subdirectory of one repo, not a repo. Cannot commit, which the user's model requires |
| sibling-lib edits | possible and isolated — the lane edits its own `candy-core/` | impossible without reaching into the live tree |
| disk | 1.9 GB per lane (`du -sh`), 1008 GB free. 6 lanes ≈ 11 GB | 124 MB per lane (109 MB of it `vendor/`) |
| copy time | **6.7 s** (supervisor-measured) / **9.7 s** (mine, same 1.9 GB source, warm-ish cache — two runs, two numbers, both fine) | not measured |

Disk is the only column lib-only wins and it wins by an irrelevant margin.

### 5.2 The two `cp` flags that matter

- **`cp -a`, never `cp -al`.** `-a` implies `--no-dereference`, so symlinks are copied *as symlinks*,
  which is what makes §5.1's first row work. `-al` hardlinks the regular files, so a lane editing a
  file in place mutates the live tree's copy of it — total isolation failure, silently.
- **Do not `rsync --exclude vendor`.** `vendor/` is gitignored, so a lane that lacks it has no way to
  get it back: `composer install` is forbidden for agents (it replaces the `sugarcraft/*` symlinks with
  Packagist copies, and the only signal is the skip count going 1 → 2). The 109 MB is the price of a
  usable lane.

### 5.2b PRECONDITION: NEVER SNAPSHOT A TREE THAT IS BEING MUTATED

**Added 2026-08-20 after the supervisor did exactly this and nearly misdiagnosed the result.**

A lane is only as good as the tree it was copied from. If any agent is running a **mutation harness**
in the source tree when you `cp -a`, the copy freezes whatever mutation happened to be applied at that
instant, and the lane's baseline is silently wrong from birth.

This is not hypothetical. Measured: a probe copy taken mid-review froze
`'fish' => self::zshCompletion()` where the live tree had `self::fishCompletion()`. The probe's suite
then reported exactly **1 failure**, and the obvious (wrong) reading was that an unrelated dependency
change had broken something. It was caught only because the diff happened to be two tokens wide. A
mutation to a comparison operator or a boundary constant would not have been.

**Why this is worse in a lane than in a probe:** every figure the lane reports afterwards — its
baseline, its deltas, its "N mutations, N killed" table — is measured against a corrupted tree. A
mutation the lane then applies and finds "already failing" reads as *killed*. That is a false green,
and false greens are how this project's §5 defect ships.

So, before `cp -a`:

```sh
# 1. Prove nothing is mid-mutation. A STILL `git status` IS NOT ENOUGH — see the note below.
pgrep -af 'bin/phpunit'                                  # must be empty
# and check every live agent transcript's mtime is not advancing

# 2. Record what you copied FROM, into the lane itself.
SRC=/home/sites/sugarcraft ; LANE=/home/sites/crush-lane-w
cp -a $SRC $LANE
{ echo "source-head: $(git -C $SRC rev-parse HEAD)"
  echo "source-status-md5: $(git -C $SRC status --porcelain=v1 --untracked-files=all | md5sum | cut -d' ' -f1)"
  echo "snapshot-at: $(date -Is)"
} > $LANE/.lane-provenance
```

If a lane later reports something surprising, `.lane-provenance` is what tells you whether to believe
it. Without it you cannot distinguish "the lane found a real bug" from "the lane was born broken".

**A STILL TREE IS NOT EVIDENCE OF AN IDLE AGENT.** A mutation loop edits one file, runs ONE test file
(~0.05s), and restores from a checksummed backup. `git status` is therefore byte-identical for minutes
at a time while a great deal of work happens. Judge liveness from the agent transcript's mtime and from
`pgrep -af 'bin/phpunit'` — never from the tree.

### 5.2c KEEPING A LIVE LANE FRESH — `fetch` yes, `pull` NO

Master moves while lanes work. It moves because the supervisor commits, and it moves **on its
own** because CI regenerates and pushes the demo GIFs — `5b77a75f vhs: regenerate demo GIFs`
landed in the middle of the C4b/C6 round, unprompted. So "the lane is 0 behind" is a claim with
a shelf life measured in minutes.

**The only refresh that is safe while an agent is working in a lane is `git fetch`.** It writes
remote-tracking refs and objects and touches neither the working tree nor the index:

```sh
for L in cmd lsp; do
  d=/home/sites/crush-lane-$L
  before=$(git -C $d status --porcelain | wc -l)
  git -C $d fetch origin master -q
  after=$(git -C $d status --porcelain | wc -l)
  echo "lane-$L behind=$(git -C $d rev-list --count HEAD..origin/master) dirty=$before->$after"
done
```

Assert `dirty` is unchanged. If it moves, something other than fetch ran.

**NEVER `git pull` / `rebase` / `merge` / `checkout` a lane whose tree is dirty and whose agent
is live.** The lane's own commit gate already does the rebase, at the single moment its tree is
clean — immediately after its commit and before its push. That is the right place for it, and
adding a second rebase from outside is how you corrupt an agent's half-finished edit.

#### The trap: a lane's `HEAD..origin/master` count is a measure of the LANE'S REF, not of drift

Measured during the C4b/C6 round, with master 6 commits ahead of both lanes:

| lane | `rev-list --count HEAD..origin/master` | truth |
|---|---|---|
| `crush-lane-cmd` | **5** | 6 behind — its agent had fetched at some earlier point |
| `crush-lane-lsp` | **0** | 6 behind — its agent had never fetched |

A full-repo `cp -a` copies the remote-tracking refs as they stood at snapshot time, and they
then age independently in each lane depending on whether that lane's agent happened to run a
`fetch`. **`lane-lsp` reported ZERO BEHIND while being six commits behind.** Both numbers were
wrong and they were wrong by different amounts, which is worse than both being wrong the same
way — it invites you to believe the one that looks plausible.

So: **always `fetch` before reading a lane's behind-count**, and never quote a lane's own
freshness figure without saying when it was fetched. This is the same defect the whole plan
tracks — a number that travelled without its domain — expressed in git refs instead of prose.

### 5.3 VERIFY EVERY LANE BEFORE HANDING IT TO AN AGENT

Four checks. All four passed on a probe copy I made and destroyed on 2026-08-19; the numbers below are
that probe's actual output, not a prediction.

```sh
LANE=/home/sites/crush-lane-w

# (1) No broken symlinks, and they point INSIDE the lane.
find $LANE/sugar-crush/vendor/sugarcraft -maxdepth 1 -xtype l | wc -l        # must be 0
readlink -f $LANE/sugar-crush/vendor/sugarcraft/candy-core                    # must start with $LANE

# (2) THE CHECK THAT MATTERS (RESUME §7): reflection must resolve inside the lane.
#     A lane can look right and still load the live tree's classes.
cd $LANE/sugar-crush && php -r 'require "vendor/autoload.php";
  echo (new ReflectionClass(SugarCraft\Core\Model::class))->getFileName(), "\n";
  echo (new ReflectionClass(SugarCraft\Crush\Theme::class))->getFileName(), "\n";'
#   probe output was:
#     /home/sites/crush-lane-probe/candy-core/src/Model.php     <- the SIBLING, inside the lane
#     /home/sites/crush-lane-probe/sugar-crush/src/Theme.php
#   If either path says /home/sites/sugarcraft, the lane is fake. Delete it and start over.

# (3) The 1-skip invariant, cheaply. The single legitimate skip lives in tests/MCP/McpClientTest.php
#     (NOT tests/McpClientTest.php — two files share the basename, cite the path).
cd $LANE/sugar-crush && vendor/bin/phpunit tests/MCP/McpClientTest.php
#   probe output: OK, but some tests were skipped! Tests: 40, Assertions: 69, Skipped: 1.  rc=0

# (4) The symlinks are seen AS symlinks — the test that self-skips when they are gone must PASS.
cd $LANE/sugar-crush && vendor/bin/phpunit \
  --filter testTheMonorepoPathRepoSymlinksAreNotFollowed tests/Tools/BuiltIn/GitignoreAwarenessTest.php
#   probe output: OK (1 test, 2 assertions), rc=0.  A SKIP here means vendor/sugarcraft is real
#   directories, the lane is testing Packagist, and every figure it will ever report is void.

# (4b) THE STRONGEST PROOF, if you can spend 3 minutes: the FULL suite, in the lane.
cd $LANE/sugar-crush && vendor/bin/phpunit > /tmp/lane-full.txt 2>&1; echo "rc=$?"; tail -4 /tmp/lane-full.txt
#   Measured on the probe copy 2026-08-19 (a copy of the CURRENT dirty tree, i.e. WITH C5a/C5b/C4a):
#     Tests: 7741, Assertions: 90040, Skipped: 1.   rc=0   Time: 03:11.497, Memory: 245.90 MB
#   A full-repo `cp -a` sandbox reproduces a fully green suite with the 1 load-bearing skip and
#   needs no symlink work at all. REDIRECT, never pipe. Needs a 600000 ms Bash timeout.

# (5) The tracked config must be byte-identical (RESUME §6).
md5sum $LANE/.sugar-crush/config.json      # must be 05480c743aff302fd6c06c5a4a4c2210

# (6) git identity.
git -C $LANE rev-parse --abbrev-ref HEAD   # master
git -C $LANE remote get-url origin         # git@github.com:detain/sugarcraft.git
git -C $LANE status --porcelain            # empty, unless you deliberately copied a dirty tree
```

**This supersedes RESUME §7 for full-repo lanes.** §7's instruction — *"re-point each relative symlink
explicitly at `/home/sites/sugarcraft/<lib>`; naive absolutising produced self-referential links for
three separate agents"* — is correct **for the lib-only sandbox it was written about** (mutation
testing on a `cp -a sugar-crush`), where the links genuinely escape. For a whole-repo lane, repointing
is not merely unnecessary, **it is the bug**: pointing a lane's `vendor/sugarcraft/candy-core` at
`/home/sites/sugarcraft/candy-core` breaks the isolation on purpose and makes lane suites load the
supervisor's files. Do not do it. Check (2) is what catches it either way.

### 5.4 Why `git worktree` is not the answer here

I checked this by running it rather than by remembering it:

```
$ git worktree add /tmp/wt-probe-master master
Preparing worktree (checking out 'master')
fatal: 'master' is already used by worktree at '/home/sites/sugarcraft'
```

Three independent reasons, any one of which is fatal for this use:

1. **The user explicitly wants every lane on `master`.** Git refuses the same branch in two worktrees.
   The workaround is per-lane branches, which is exactly the model the user rejected.
2. **A fresh worktree has no `vendor/`** — it is gitignored, so `git worktree add` does not populate it.
   The only supported ways to get it are `composer install` (forbidden) or `cp -a` the vendor tree in
   (at which point you have done the expensive part of the full copy and gained nothing).
3. Worktrees share one object store and one set of refs. Five lanes updating `refs/heads/master`
   through a shared `.git` is a race with no upside over five independent repos that each push.

**The one thing worktrees would give you** is cheap disk. Disk is 1008 GB free. It is not a problem.

### 5.5 The four orphaned processes already on this box

```
1270640  php8.3 -S 127.0.0.1:46023  /tmp/mosaic-ssrf-…/router.php
1270642  php8.3 -S 127.0.0.1:40025  /tmp/mosaic-ssrf-…/router.php
1270644  php8.3 -S 127.0.0.1:37641  /tmp/mosaic-ssrf-…/router.php
1270646  php8.3 -S 127.0.0.1:41393  /tmp/mosaic-ssrf-…/router.php
```

Measured 2026-08-19: four `php -S` servers left behind by **candy-mosaic's** SSRF tests, 3h18m old,
0.0% CPU each. They are not sugar-crush's and sugar-crush's suite does not start them.

**Why they belong in a concurrency document.** They are the standing proof that the "never run a global
`pkill`" rule (RESUME §4, and every agent-rules file) is load-bearing *and gets sharper with N lanes*.
In solo mode a stray `pkill php` kills your own run. With 4 lanes it kills four agents' suites at once
and each of them reports a red suite it cannot explain. **Kill only PIDs you started** — and if these
four ever need cleaning, the supervisor kills them by the PIDs above, in the live tree, with no lane
running.

They hold 4 loopback ports. sugar-crush's own suite binds ephemeral ports in some fixtures; **I did not
measure port-collision risk across N concurrent sugar-crush suites** and I am not going to guess at it.
If two lanes' suites ever fail simultaneously in a network-shaped test, this is the first hypothesis.

---

## 6. THE RULES THAT KEEP LANES FROM COLLIDING

### 6.1 File ownership

**A lane may only edit files its lane owns (§2.6).** Not "should" — may. A lane that discovers it needs
a file another lane owns **stops and reports**; the supervisor either hands the file over (by pausing
the owning lane) or re-lanes the item. Silent cross-lane edits are how the 2026-08-10 Wave 1 run
produced a tree that could not be split into per-step commits by hand.

That run is the empirical basis for this whole section and it is worth stating plainly: **7 tracks ran
concurrently in one uncommitted tree, every reviewer ran `git diff` and saw all 16 steps' work at once,
and nearly every review returned a traceability BLOCKER that was an artifact of the harness rather than
a code defect.** Isolated directories fix precisely that — a lane's `git diff` shows only its own work.
That is the main thing isolation buys, more than speed.

### 6.2 A corollary that RESUME §3 could not have known

RESUME §3 says: *"Serialise anything touching the same file. A suite run that loads a file another lane
is editing shifts `file(__FILE__)` ranges against already-loaded reflection and produces phantom
failures. Serialise the runs, not just the writes."*

**In isolated directories that hazard is gone**, because a lane's suite loads only its own copy's files
— which is exactly what §5.3 check (2) proves (`ReflectionClass::getFileName()` inside the lane, for
both a sibling lib and sugar-crush itself). **So: lanes may run their suites concurrently.** This is my
reading of §3's mechanism plus a direct measurement of the isolation, not something §3 says. If phantom
failures ever appear anyway, re-serialise the runs and tell the supervisor — but do not serialise them
pre-emptively, because a serialised test queue would remove most of the value of running 4 lanes.

### 6.3 Commit and push discipline

Each lane, per item:

```sh
cd /home/sites/crush-lane-<x>
git add -- <only this item's paths>          # never `git add -A`, never `git add .`
git commit                                    # plain commit. NEVER --no-verify, NEVER -c core.hooksPath=/dev/null
git pull --rebase origin master               # ALWAYS, every time, not only when drift is expected
git push origin master                        # if rejected: pull --rebase again and retry
```

**`git pull --rebase` is unconditional, and here is the measured reason.** CI pushes to `master` on its
own: at the moment of writing, local `master` is **1 ahead / 1 behind** `origin/master`, and the
upstream commit is `2f51b1a3 "vhs: regenerate demo GIFs"` — the `.github/workflows/vhs.yml` job
re-rendering and pushing autonomously. It touched **7 binary `.gif` files** under `candy-core/.vhs/`
and `sugar-crush/.vhs/`. So drift on `master` arrives from a non-human writer that no lane coordination
can see.

**Which produces a hard rule: no lane ever commits a `.vhs/*.gif`.** A rebase conflict on a binary file
is not hand-mergeable, and `AGENTS.md` already says authors do not commit GIFs — CI renders them. Lane
D writes `.tape` files and never `.gif` files. This makes the one unmergeable conflict class
*impossible* rather than merely unlikely.

**On a rebase conflict:**

1. **`git rebase --abort`. Immediately.** Do not resolve.
2. Report to the supervisor: which files, which two commits, and the lane's own commit sha.
3. **A lane never resolves a conflict in a file it does not own.** If the conflict is in a file it
   *does* own, it may still only resolve it after the supervisor confirms nobody else is in there.
4. If a lane's push is rejected three times in a row, stop and report — that is a sign two lanes are
   writing the same file, i.e. a lane-assignment bug, not a git problem.

**Commit only the item's own paths.** In a lane the tree is clean at the start of an item, so
`git status` is that item's work — but `git add -A` will also sweep up anything a previous item left,
and (in a full-repo lane) any `*/composer.json` the path-repo injection wrote. That last one is a
tracked-file landmine: `php tools/check-path-repos.php --no-lib-path-repos` must exit 0 before **every**
commit, in **every** lane.

### 6.4 Shared prose — the supervisor owns all of it

Every bundle wants to write the same handful of prose files. At N=1 that is a sequencing detail; at
N=4 it is a conflict on every single item. The rule that removes it:

| file | owner | why |
|---|---|---|
| `crush_code.md` (the status block) | **supervisor only** | Every bundle wants to tick its item. RESUME already records "C1's agent is live in `crush_code.md`… a second writer there loses one of the two edits" — that happened at N=1. Agents **never** touch this file. They report "P8.3 done" and the supervisor ticks it. |
| `docs/plans/crush_code_hardening_backlog.md` | **supervisor only** | RESUME §3 requires *every* bundle to record deferred findings here, so at N=4 it is guaranteed to be four-way contended. Lanes put deferred findings **in their agent report**, in the What/Where/Severity/Evidence/Step/Blocked-on format, and the supervisor appends. |
| `docs/plans/crush_code_RESUME.md`, `crush_code_worklog.md` | **supervisor only** | already the rule (RESUME §6) |
| `docs/plans/crush_code_concurrency.md` (this file) | **supervisor only** | it is the map; a lane editing the map is a lane redrawing its own boundary |
| `sugar-crush/README.md` | **lane D**, with one carve-out | README is the most-contended non-plan file. Give it to D. **Carve-out:** the suite figure at `:629` and the "all 10 built-in" at `:651` are supervisor-only (Tier 4 / §6.5). |
| `sugar-crush/docs/**` | **lane D** | |
| `sugar-crush/composer.json` | **supervisor only** | §2.4 — a `require` bump needs a `composer update` that agents may not run |
| `.github/workflows/vhs.yml` | **supervisor only** | hand-maintained `all=(…)` array; only moves if lane D adds a tape |
| `docs/_data/sugar-crush.{json,body.html}` + `docs/lib/sugar-crush.html` | **lane D** | and the `.html` is **generated** — edit the `_data` sources, run `php tools/gen-docs.php`, never hand-edit the page |

**A lane's deliverable is a commit plus a report. It is never a plan edit.** That single sentence
removes most of the cross-lane conflict surface.

### 6.5 The census token — the collision that merges CLEANLY and is silently wrong

Adding any `src/**.php` file to `sugar-crush` moves five counters, and RESUME requires all five in the
same diff:

1. `tests/Tools/BuiltInToolCorpusTest.php` — file count. **At `HEAD` (`1c78cc7a`): `assertSame(276, …,
   'php files under src/')` at lines 297 and 389.** In the current working tree, C5b has already
   bumped both to **277** (it adds `src/Cli/Subcommands.php`).
2. same file — declaration count. **`HEAD`: `assertSame(295, $declarations, 'top-level declarations in
   them')` at :390. Working tree: 296.**
3. same file — the secondary-declaration figure, **`assertSame(19, array_sum(array_map('count',
   $secondary)))` at :380** (19 secondary declarations across 8 files). Unchanged by both.
4. `tests/Integration/BinSugarcrushWiringTest.php::crushSourceFiles` — a data provider that walks
   `src/` **recursively** (`RecursiveDirectoryIterator`, `.php` only) and yields one case per file, so a
   new file adds a test case to every test that consumes the provider.
5. `tests/Support/ContainedPathInventoryTest.php::ROUTED_CALL_SITES` (:172) and
   `tests/Support/ReadPathCensusTest.php::READ_PATHS` (:122) — **only when the new file touches paths.**
   `ReadPathCensusTest`'s own message states the invariant: *"every read/execute sink in `src/` must
   carry a verdict in READ_PATHS, and every verdict a sink."*

**Plus, for a new TOOL specifically** (P2.7 LspTool, P8.13 Task) — two prose figures that are not in
RESUME's list of five and that I found by grep:

6. `sugar-crush/README.md:651` — *"all 10 built-in"*.
7. `sugar-crush/src/Cli/Bootstrap.php:3304` and `:3309` — *"the ten built-ins keep the wire order"* and
   *"The doc-block above deliberately still says TEN"*. That second comment is the trap: it explains
   that TEN is correct **for the built-in set** even though `tools()` returns more, so a naive
   count-bump is wrong and a naive leave-alone is also wrong. Read the comment before touching it.

**Why this is dangerous under concurrency, and it is worse than an ordinary conflict.** If lane W and
lane C each add one `src` file, each edits `assertSame(277, …)` → `assertSame(278, …)`. Git sees the
same line changed to the **same text** in both branches and **merges it cleanly**. The tree then claims
278 while the truth is 279, and the failure surfaces as an unrelated-looking census test failure in
whoever pulls next.

> **The rule: exactly one lane at a time may hold a census-moving item. Call it the census token, and
> the supervisor hands it out.** Of the 27 remaining items, **5 add a `src` file**: P2.7, P2.9, P6.2,
> P8.8, P8.13 — four of them in lane W, one (P8.8) in lane C. So in practice: **lane W holds the token
> by default, and lane C must ask for it before starting P8.8.** That is the entire cost of the rule.

### 6.6 What a lane hands back

Per `docs/plans/crush_agent_rules.md`'s reporting section, plus three lane-specific additions:

8. **The lane's own commit sha(s)** — the supervisor's re-verification reads `git show <sha>`, which is
   also the mechanism that keeps reviewer context small (§7).
9. **Whether the push landed**, and how many rebases it took. Three-plus means a lane-assignment bug.
10. **Any file it touched outside its lane's ownership**, even if the edit was correct and necessary.
    This is the one that catches a mis-assignment before it becomes a conflict.

---

## 7. HOW THIS PLUGS INTO `Workflow`

The work stays workflow-driven; concurrency is a change to *how many workflow scripts are in flight and
where they point*, not a change to the loop. The per-bundle loop (RESUME §2 and §"THE WORKFLOW") is
unchanged: **measure → implement → review → fix → re-verify → the supervisor runs the suite and
commits.**

### 7.1 The script shape that exists today

The live script is `crush-c5-c4a-wf_85ae4115-4fe.js`. Its API, read off that file:

- `export const meta = { name, description, phases: [{title, detail}, …] }`
- `phase('C5a')` — marks the phase boundary
- `const result = await agent(promptString, { label: 'c5a:implement', phase: 'C5a' })` — the agent's
  report comes back as a string
- prompts are composed from `const PRE = …` (rules pointer + baseline) and a per-bundle
  `const GROUND = …` (the supervisor's measurements)
- stages are chained by string interpolation: the review prompt embeds `${c5aImpl}`

**One thing in that live script is already broken and must be fixed in any copy of it:** its
`const RULES` points at
`/tmp/claude-1000/…/scratchpad/rules.md` — a session-scoped path that does not survive a compact.
`docs/plans/crush_agent_rules.md` exists precisely to replace it, and its own header says so. **Every
new script must use the repo path.**

### 7.2 Lanes as arrays; `pipeline()` inside a lane, `parallel()` across lanes

```js
export const meta = {
  name: 'crush-lanes-round-1',
  description: 'Lane W (P6.2) serial; lanes C, S, D concurrent. See docs/plans/crush_code_concurrency.md',
  phases: [{ title: 'round 1', detail: 'W:P6.2 | C:P6.1,P8.10 | S:P8.3 | D:P8.6,P7.5' }],
}

// Point agents at PATHS. Never inline the rules — RESUME's context-budget finding.
const RULES = 'docs/plans/crush_agent_rules.md'
const MAP   = 'docs/plans/crush_code_concurrency.md'

const LANES = {
  W: { dir: '/home/sites/crush-lane-w', owns: 'src/Cli/**, bin/, new src/Tools/BuiltIn/*, new src/Config/*' },
  C: { dir: '/home/sites/crush-lane-c', owns: 'src/Context/**, src/Agents/WorktreeConfig.php' },
  S: { dir: '/home/sites/crush-lane-s', owns: 'src/Tui/**' },
  D: { dir: '/home/sites/crush-lane-d', owns: 'sugar-crush/docs/**, .vhs/**, examples/**' },
}

const pre = (lane) => `Your repo root is ${LANES[lane].dir}. Read ${LANES[lane].dir}/${RULES} and follow
it exactly — every absolute path in it that begins /home/sites/sugarcraft means ${LANES[lane].dir} for
you. You must not read or write anything under /home/sites/sugarcraft.
Your lane owns: ${LANES[lane].owns}. A file outside that set is a STOP-and-report, not an edit.
You do NOT edit crush_code.md or docs/plans/crush_code_hardening_backlog.md — report findings instead.
Before any commit: cd ${LANES[lane].dir} && php tools/check-path-repos.php --no-lib-path-repos  (rc 0).
`

// One lane = one pipeline of stages, strictly serial inside itself.
const laneRun = async (lane, brief, label) => {
  const impl = await agent(`${pre(lane)}${brief}`, { label: `${label}:implement`, phase: 'round 1' })
  const sha  = await agent(`${pre(lane)}Commit ONLY this bundle's paths in ${LANES[lane].dir}, then
git pull --rebase origin master && git push origin master. On ANY rebase conflict: git rebase --abort
and report. Return the commit sha ALONE as the last line.
WHAT WAS IMPLEMENTED:\n${impl}`, { label: `${label}:commit`, phase: 'round 1' })
  // Reviewers read the DIFF, not the implementer's report — this is the context-budget fix.
  const rev  = await agent(`${pre(lane)}ADVERSARIAL REVIEW. You did not write this. Read the diff with
\`git -C ${LANES[lane].dir} show ${'${sha}'}\` — the sha is the last line of: ${sha}
Do NOT ask for the implementer's report. A review that finds nothing has failed.
Mutation budget 5-8, each written as a VERBATIM old -> new edit.`, { label: `${label}:review`, phase: 'round 1' })
  return { impl, sha, rev }
}

// Lanes run CONCURRENTLY because their file sets are disjoint (see the map, §3.1).
const [w, c, s, d] = await Promise.all([
  laneRun('W', BRIEF_P6_2,  'w-p62'),
  laneRun('C', BRIEF_P6_1_P8_10, 'c-p61'),
  laneRun('S', BRIEF_P8_3,  's-p83'),
  laneRun('D', BRIEF_P8_6,  'd-p86'),
])
```

If the workflow runtime exposes a first-class `parallel()`/`pipeline()` instead of
`Promise.all`/`await`-chains, use it — the shape is the same. **What must not change is that a lane is
a serial pipeline and lanes are the only thing that runs in parallel.** Parallelism *inside* a bundle
is fine only for independent review lenses (RESUME's own rule), never for two implementers.

**A note on `args`:** a prior run had the `Workflow` tool's `args` parameter arrive inside the script as
a stringified blob rather than an array, killing a 50-item batch on launch with `agent_count: 0`. Lane
definitions are static — **write them as a literal `const` in the script body**, never route them
through `args`.

### 7.3 On `isolation: 'worktree'`

The `Agent` tool offers `isolation: "worktree"`, which gives an agent its own git worktree. **Do not use
it for these lanes**, for §5.4's reasons — chiefly that the user wants every lane on `master` and git
refuses (verified: `fatal: 'master' is already used by worktree at '/home/sites/sugarcraft'`), and that
a fresh worktree has no `vendor/`. The `cp -a` lane directories are the isolation mechanism here; agents
are pointed at them by absolute path.

### 7.4 Keeping per-agent context under ~200k

The user's standing instruction is ~200k per agent, not 360k. The four causes RESUME identified and the
lane-specific version of each fix:

1. **Inlined preamble** → point at `docs/plans/crush_agent_rules.md` **by path**, per lane dir. The
   `pre(lane)` helper above is ~8 lines and carries only what is lane-specific.
2. **Stage inheritance** → **commit before review, and have the reviewer read `git show <sha>`.** The
   sketch above does this; it is strictly better under concurrency than at N=1, because a lane's
   `git show` is guaranteed to contain only that lane's work (§6.1).
3. **One agent owning several parts across several libs** → split into narrow reviewers, one lens each.
   Lane boundaries already do most of this work.
4. **Many mutations × full suite** → cap 5-8 mutations, one test FILE while iterating, full suite at
   most twice. Unchanged. Note the standing facts: `vendor/bin/phpunit tests/Cli` as a **directory**
   hangs >4 min (backlog E29) while a single file inside it runs in ~0.05 s; the full configured run
   needs a **600000 ms** timeout; **redirect, never pipe**; judge by `$?`, never the banner.

Do **not** hand this concurrency map to implementing agents. It is ~600 lines of supervisor context and
none of it changes what an implementer does. Agents get `crush_agent_rules.md` + their lane dir + their
ownership line. The map is for the supervisor.

---

## 8. WHAT I DID NOT MEASURE

Stated explicitly so nobody reads an estimate as a measurement (RESUME §5).

- **Whether P2.9 (plugins) and P8.13 (Task tool) actually need `src/Chat.php`.** Marked ⚠ likely in the
  table. Inferred from item text; there is no `PluginLoader` and no `Task` tool in `src/` to grep. Both
  bundles' measure steps must settle it, and if either does need `Chat.php`, §3.2's U/W carve-out
  applies to it too.
- **The S/M/L size column.** Judgement, not measurement.
- **Port-collision risk across N concurrent sugar-crush suites.** §5.5.
- **Whether N concurrent full suites slow each other down.** One full suite in a probe copy took
  **3m11.497s** against the ~2m20s figure RESUME records for the live tree. Three confounds make that
  gap uninterpretable: the machine was at load ~4.5 with other work running, the probe carried the
  three in-flight bundles so the suite was **7,741 tests rather than HEAD's 7,637**, and I ran no
  control. **The observation supports no conclusion about contention** and is recorded only so the next
  person does not mistake a slow lane suite for a regression. If you want the real number, run the same
  suite in the live tree and in one lane back-to-back, same tree state, nothing else running.
- **Whether the five existing `.vhs` tapes show what P8.6 asks for.** §2.5(b) establishes that the tapes
  and their example drivers exist; it does not establish that their content satisfies the item.
