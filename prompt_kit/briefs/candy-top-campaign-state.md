# candy-top campaign — supervisor handoff state

Authoritative plan: `plan_top.md` (✅ + commit hash marks = reviewed, fixed, committed). This file is the
supervisor's working state for resuming after a context reset. Update it whenever the in-flight set changes.

_Last updated: 2026-10-08 (before /compact)._

## Operating rules (user-mandated)
- Commit straight to `master`, author/committer **Joe Huss <detain@interserver.net> only**, **no Co-Authored-By**,
  **no PRs/branches**. Push after each commit; if rejected, `git fetch && git merge --no-edit origin/master`
  (upstream usually only adds regenerated VHS GIFs) then push. Never rebase/stash (agents share the tree).
- Every unit of work: implementer agent → independent reviewer agent → fix round(s) → re-review until APPROVE
  → supervisor commits ONLY that unit's paths → mark `plan_top.md` with ✅ + hash (separate small commit).
- Up to ~5 agents concurrently on disjoint paths. Agents never git stash/checkout/reset/clean/commit; supervisor
  commits. When a shared file (e.g. `candy-top/lang/en.php`) holds two units' edits, stage partially
  (build the blob without the other unit's block, `git hash-object -w` + `git update-index --cacheinfo`).
- Skip Caliber entirely. Don't touch sugar-crush. `unset GITHUB_TOKEN` before any `gh`.
- Docs for every feature (phase P-H). Ask the user only for genuine decisions; otherwise keep going.

## Done (on master)
Lib lanes L1 `0153b65e4` · L2 `073913a44` · L3 `37f5c6744` · L4 `1bbbca37f` · L5 `57cd08b9f` · L6 `0859cfa4f` ·
L7 `07a1e5e6f` · L8 `39c65280c` · integration `03d5ef164` · candy-top Theme `672c64680` · Collect `b2d9207cc` ·
Config+Lang `7c893a54b` · proc_open census fix `ea1949fd2` · Wave U0+U1 collectors `02f178b91` · Wave U2 block2
`503ee9bb7` · Wave U config keys `96dcdc43a` · P-H sibling-lib docs `776bd06b5` · Wave U1b VM awareness `b071eb8b7` · candy-core resize repaint `5442e8b1f` · P-A app shell `b1bd8fc12` · P-C net panel `7ac64e5f3` (+ sugar-dash NetAutoScale::rescaleNow) · P-B cpu+mem `8e4559946`.
btop-derived byte-exact oracles: `prompt_kit/tools/btop-{graph,netscale,theme}-oracle.cpp`.
References: `prompt_kit/findings/{btop-upstream-prs,nvidia-smi-skynet2,kvm-reference,freebsd-reference}.md`.
skynet2 (`ssh root@skynet2`, read-only nvidia-smi queries OK) = 4× RTX PRO 6000 GPU host.

## In flight at handoff (UNCOMMITTED in the working tree)
Launched 2026-10-08 in parallel (shared brief: `prompt_kit/briefs/candy-top-panel-phase-common.md`):
- **P-E proc** (implementer running) — signal popup deferred to P-F overlay seam.
- **P-D disks+battery** (implementer running) — fills MemPanel::withDisks / CpuPanel::withBattery seams; needs App clock battery reserve (paintClock battery:) wired.
Each agent reports an exact file list; commit only that list (lang/en.php, Panels.php, CALIBER_LEARNINGS.md
are shared → stage partially). Chrome goldens use frozen `Panels::placeholders()` (`4186902f5`, `c1613c6aa`).

## Next
- After P-A commits: launch **P-B (cpu+mem), P-C (net), P-D (disk+battery), P-E (proc)** in parallel, each a
  Panel file + one `Panels::standard()` line, folding in their Wave U3 items (plan Wave U section) — e.g.
  P-B #1785/#1747/#1739 + #1614/#1008 rules; P-C #1573; P-D #1700; P-E #1859/#1823/#1546/#1791a/#1873 +
  U1b VM tags; Gpu `withProcesses()` must be enabled where per-process GPU memory is shown.
- Then **P-F** (options menu via OptionRow/TextEdit, presets, keybind overlay; #1476/#1411/#1791b/#1849; hold-
  repeat ±1000ms; refused-toggle message box), **P-G** (TTY polish, mellow theme #1683 → bump 41-pinned tests,
  VHS tape + vhs.yml `all=(…)` entry), **P-H candy-top half** (full README incl. config-key table from Schema,
  keys, mouse, themes, collectors/permissions, VM/container tags; docs/_data refresh + gen-docs; drift tests;
  refresh stale "scaffold" wording in README/docs/index.html; MATCHUPS 🟡→🟢 at v1).
- Post-v1: U4 (P-I GPU/NPU multi-vendor, container/VM box, tree-state persistence), U5 FreeBSD.

## Known open issues (not campaign-caused)
- candy-core `DescriptorSinkArgumentCensusTest` has 2 failures from sugar-crush commit `f41b307ae`
  (2026-10-05) — pre-existing red on master; reported to user, not fixed (outside plan) unless asked.
- `terminal_sync=false` ignored (candy-core always wraps DEC 2026); sampling runs on the loop thread (P-E must
  keep ProcList under the R4 50 ms budget).
