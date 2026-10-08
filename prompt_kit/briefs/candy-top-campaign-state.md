# candy-top campaign — supervisor handoff state

Authoritative plan: `plan_top.md` (✅ + commit hash marks = reviewed, fixed, committed). This file is the
supervisor's working state for resuming after a context reset. Update it whenever the in-flight set changes.

_Last updated: 2026-10-08 (second /compact, P-F2 in flight)._

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
`503ee9bb7` · Wave U config keys `96dcdc43a` · P-H sibling-lib docs `776bd06b5` · Wave U1b VM awareness `b071eb8b7` · candy-core resize repaint `5442e8b1f` · P-A app shell `b1bd8fc12` · P-C net panel `7ac64e5f3` (+ sugar-dash NetAutoScale::rescaleNow) · P-B cpu+mem `8e4559946` · P-E proc `52f994d15` · P-D disks+battery `a856be8bd` · P-G mellow theme `632e08d49` · P-F1 overlays/menus `083176989` · P-G VHS tape `3469999a7`.
btop-derived byte-exact oracles: `prompt_kit/tools/btop-{graph,netscale,theme}-oracle.cpp`.
References: `prompt_kit/findings/{btop-upstream-prs,nvidia-smi-skynet2,kvm-reference,freebsd-reference}.md`.
skynet2 (`ssh root@skynet2`, read-only nvidia-smi queries OK) = 4× RTX PRO 6000 GPU host.

## In flight at handoff (UNCOMMITTED in the working tree)
- **P-F2** options menu / presets / config persistence / ctrl+r reload (#1476 proc_box_width_percent + Shift/Alt+Shift
  arrows + preset 4th field, #1411 tab digits, #1791b section headings, #1849 reload clears memos) + FilterEdit →
  sugar-bits TextEdit + P-F1 leftovers (proc ↑ arrow when following, frozen backdrop refresh on menu close/switch,
  renice backspace keeps last parsed value, cpu title buttons on mouse drag). Brief: `prompt_kit/briefs/candy-top-pf-common.md`
  + the scope list in the P-F2 agent prompt (summarised here). sugar-bits require committed `892cc0e7b`; candy-top
  vendor relinked (`php scripts/refresh-deps.php --mode=linked --libs=candy-top`; candy-testing now installed).
  Implementer agent was running at /compact. Every uncommitted path under `candy-top/` is P-F2's (only stray
  untracked file outside it: `server.ansi` at repo root — NOT campaign, never stage it).
  **If the agent is gone after the reset:** inspect `git status`/`git diff candy-top`; if the work looks complete
  (suite green: `cd candy-top && vendor/bin/phpunit`), launch an independent reviewer on it; if incomplete, launch
  a new implementer to finish the P-F2 scope above from the current tree (don't discard work). Then review → fix →
  commit → plan mark (plan row `| P-F (F1 overlays/menus ✅ `083176989`; F2 pending) |` → mark F2 ✅ + hash).

## Next
1. **P-H candy-top half** (after P-F2 commits): full `candy-top/README.md` (install, run, `--fake`/`--config`/tty,
   every panel, every key from `Input\KeyTable`, mouse, options menu, presets, config-key table derived from
   `Config\Schema`, themes list (42) + user themes, TTY mode, collectors/data sources/permissions, VM/container
   tags, adopted Wave U PR features, deviations from btop listed in CALIBER_LEARNINGS), README demo GIF embed
   (`https://raw.githubusercontent.com/detain/sugarcraft/master/candy-top/.vhs/top.gif`), docs drift tests (README
   key table ⇔ KeyTable, config table ⇔ Schema, like sugar-crush's drift guards), `docs/_data/candy-top.{json,body.html}`
   refresh then `php tools/gen-docs.php` (never hand-edit docs/lib/*.html), refresh stale "scaffold" wording in root
   README / docs/index.html, MATCHUPS 🟡→🟢, CALIBER_LEARNINGS tidy. Review → fix → commit → mark P-H ✅.
2. **P-G remainder**: TTY polish check (`--tty` / tty_mode demo) — mark P-G fully ✅ when done (mellow `632e08d49`
   and tape `3469999a7` already done).
3. Post-v1 (only after the above; ask nothing, proceed): U4 (P-I GPU/NPU multi-vendor, container/VM box,
   #1791c tree-state persistence in XDG state file), U5 FreeBSD collectors (reference `prompt_kit/findings/freebsd-reference.md`).
- Perf note: real ProcList 40-47 ms @1160 pids, 56-72 ms with io (kernel floor); sampling is on the loop thread.
- Deviations/deferrals are recorded per phase in `candy-top/CALIBER_LEARNINGS.md` (P-A…P-F sections).

## Process notes
- Snapshot-test every commit in isolation: `git checkout-index -a --prefix=$JOB_TMP/idx/` then
  `cp -a candy-top/vendor $JOB_TMP/idx/candy-top/vendor` and run phpunit there (JOB_TMP=/home/my/.claude/jobs/b8d2a5fd/tmp).
- Shared files (lang/en.php, Panels.php, CALIBER_LEARNINGS.md) staged partially via python + `git hash-object -w`
  + `git update-index --cacheinfo` when another unit's edits are present.
- Never run `refresh-deps.php` with an uncommitted composer.json (it `git checkout`s `*/composer.json`).

## Known open issues (not campaign-caused)
- candy-core `DescriptorSinkArgumentCensusTest` has 2 failures from sugar-crush commit `f41b307ae`
  (2026-10-05) — pre-existing red on master; reported to user, not fixed (outside plan) unless asked.
- `terminal_sync=false` ignored (candy-core always wraps DEC 2026); sampling runs on the loop thread (P-E must
  keep ProcList under the R4 50 ms budget).
