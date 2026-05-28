# Upstream parity audit — agent prompt template

Scans every upstream Charmbracelet (and adjacent) repo for newly
merged features and high-comment open issues. Identifies port
candidates and updates `UPSTREAM_OPPORTUNITIES.md`.

This audit is **research-only by default** — no code changes, just
findings. Code follows in separate port-wave PRs (drafted using the
output of this audit).

Copy the fenced block below into your chat app.

---

```
You are a research analyst auditing upstream parity for the SugarCraft
monorepo. Repo: https://github.com/detain/sugarcraft  (license: MIT)

## Project

SugarCraft is a PHP 8.1+ monorepo of 40+ TUI library ports of the
Charmbracelet (Go) ecosystem and adjacent OSS projects. Each
SugarCraft lib mirrors a specific upstream repo; the mapping lives in
`MATCHUPS.md`.

## Git identity (required on every commit)

All commits in this repo are authored as:

  Name:  Joe Huss
  Email: detain@interserver.net

Use `--author "Joe Huss <detain@interserver.net>"` on every `git commit`.
Do NOT use the agent's default identity, your own identity, or any
other email. (This audit is doc-only — only `UPSTREAM_OPPORTUNITIES.md`
gets committed — but the author rule still applies.)

## Your task

For every upstream repo in `MATCHUPS.md`, scan recent merged PRs and
high-comment open issues. For each finding, classify:

- ✅ already present in our port (no action needed)
- 🟡 partially present / variation landed
- 🔴 missing — port candidate (estimate effort + benefit + downside)
- ⚪ not applicable (Go/Rust/etc. semantics that don't translate to PHP)

Compile findings into per-upstream sections of
`UPSTREAM_OPPORTUNITIES.md`. Recommend a prioritized port wave at
the bottom.

NO code changes in this audit. Pure research + doc updates.

## Read these files first

1. `CLAUDE.md`, `AGENTS.md`, `CONTRIBUTING.md`
2. `MATCHUPS.md` — every upstream → port row
3. `UPSTREAM_OPPORTUNITIES.md` — current state, snapshot date, format
4. `CONVERSION.md` — phase roadmap; tells you what's intentionally
   deferred (e.g. v2 work)
5. For each lib being audited: `<lib>/src/` (skim public classes —
   you need to recognize whether an upstream change has a SugarCraft
   equivalent)

If you cannot run `gh api`, ASK the user to paste:
- The output of `gh api 'repos/<owner>/<repo>/pulls?state=closed...'`
  for each upstream
- The relevant SugarCraft source files when you need to verify
  presence/absence of a feature

## Methodology — per upstream

For EACH row in MATCHUPS.md (libraries + apps tables):

### Step 1 — pull recent merged PRs

  unset GITHUB_TOKEN
  gh api 'repos/<owner>/<repo>/pulls?state=closed&per_page=50&sort=updated&direction=desc' \
    --jq '.[] | select(.merged_at != null) | {n: .number, t: .title, m: .merged_at[:10], u: .html_url}' \
    | jq -s '.'

Filter: keep only PRs merged since `UPSTREAM_OPPORTUNITIES.md`'s snapshot
date. If no snapshot date, use the last 6 months.

### Step 2 — filter out chore-class titles

Drop PRs whose title matches `^(chore|docs|deps|ci|build|test|style|refactor)[(:]`.
These are noise.

### Step 3 — classify each remaining PR

For each survivor, read the diff (`gh pr diff <number> -R <owner>/<repo>`)
or at minimum the description. Classify against the SugarCraft port:

| Status | Meaning | Action |
|---|---|---|
| ✅ | Feature already present in our port | Skip — note in working table |
| 🟡 | Variation landed; some surface present, some missing | Add row noting the gap and what's missing |
| 🔴 | Missing entirely — port candidate | Add row with effort + benefit + downside |
| ⚪ | Go/Rust-specific (channels, runtime, etc.) — doesn't translate | Note + skip |

### Step 4 — high-comment open issues

  gh api 'repos/<owner>/<repo>/issues?state=open&per_page=20&sort=comments&direction=desc' \
    --jq '.[] | select(.pull_request == null) | {n: .number, t: .title, c: .comments, u: .html_url}'

Top 5-10 most-commented open issues per upstream. Same classification
as PRs.

### Step 5 — write the section

Use the existing format in `UPSTREAM_OPPORTUNITIES.md`. Two tables per
upstream:

  ### Recent merged PRs

  | Upstream | Title | Status in <port> | Notes |
  |---|---|:--:|---|
  | #N (date) | <title> | <icon> | <effort/benefit/downside> |

  ### High-comment open issues

  | Upstream | Title | Applicability | Recommendation |
  |---|---|---|---|
  | #N | <title> (Nc) | <icon> + 1-2 sentences | Effort estimate |

For 🔴 entries (port candidates), include in the Notes column:

- **Effort**: hours / days estimate
- **Benefit**: what user-visible improvement results
- **Downside**: API churn, backward-compat risk, snapshot-test churn

### Step 6 — recommend a port wave

At the bottom of `UPSTREAM_OPPORTUNITIES.md`, replace or update the
"Recommended next-port wave" section. Pick 3-5 highest-leverage 🔴
items. Order by:

1. Anything fixing a real bug users hit (look for many issue comments)
2. High-leverage features that unlock new use cases (e.g. Tree component)
3. Smaller items that bundle into a "polish wave" PR

Also list "smaller fixes worth bundling into a single PR" — for items
that are tiny but together justify a PR.

## Per-upstream coverage list (from MATCHUPS.md as of last refresh)

### Charmbracelet libraries
- charmbracelet/bubbletea → candy-core
- charmbracelet/lipgloss → candy-sprinkles
- charmbracelet/harmonica → honey-bounce
- lrstanley/bubblezone → candy-zone
- charmbracelet/bubbles → sugar-bits
- NimbleMarkets/ntcharts → sugar-charts
- charmbracelet/huh → sugar-prompt
- charmbracelet/glamour → candy-shine
- charmbracelet/fang → candy-kit
- charmbracelet/wish → candy-wish
- charmbracelet/promwish → candy-metrics
- charmbracelet/log → candy-log
- charmbracelet/colorprofile → candy-palette
- treilik/bubblelister → candy-lister
- treilik/bubbleboxer → sugar-boxer
- rmhubbert/bubbletea-overlay → sugar-veil
- KevM/bubbleo → sugar-crumbs
- Genekkion/theHermit → candy-hermit
- Evertras/bubble-table → sugar-table
- erikgeiser/promptkit → sugar-readline
- EthanEFung/bubble-datepicker → sugar-calendar
- daltonsw/bubbleup → sugar-toast
- 76creates/stickers → sugar-stickers

### Apps
- charmbracelet/gum → candy-shell
- charmbracelet/freeze → candy-freeze
- charmbracelet/glow → sugar-glow
- charmbracelet/sequin → sugar-spark
- charmbracelet/wishlist → sugar-wishlist
- charmbracelet/skate → sugar-skate
- charmbracelet/pop → sugar-post
- charmbracelet/soft-serve → candy-serve
- charmbracelet/crush → sugar-crush
- Broderick-Westrope/tetrigo → candy-tetris
- yorukot/superfile → super-candy
- jesseduffield/lazygit → sugar-stash
- jorgerojas26/lazysql → candy-query
- Rtarun3606k/TakaTime → sugar-tick
- maxpaulus43/go-sweep → candy-mines
- namzug16/gifterm → candy-flip
- kbrgl/flapioca → honey-flap

Verify against current MATCHUPS.md — the list above may have grown.
Use MATCHUPS.md as the source of truth.

## Special handling

### Bubbletea v2 work

Upstream is incrementally splitting KeyMsg into KeyPressMsg /
KeyReleaseMsg / KeyAutoRepeatMsg, refactoring renderer internals,
etc. Per CONVERSION.md, SugarCraft tracks v1 parity; v2 is a separate
roadmap item.

For v2 changes: classify as ⚪ (deferred) and note in the wave
recommendation that "bubbletea v2 work is out of scope for this port
wave; track separately".

### Recently-evaluated repos

If you find an upstream that's already been evaluated and decided
against (look in `plans/evaluated-skipped.md` if it exists), skip it
and note the decision in the working notes.

### x/ extras

`charmbracelet/x` (the experimental tree) is covered by the plans in
`plans/x-*.md`. Don't re-investigate — defer to those plans.

### Adjacent ecosystems (ratatui, etc.)

If MATCHUPS.md doesn't list it, it's not in our scope. ratatui /
go-termimg / ratatui-image have already been evaluated; see
`plans/ratatui-widget-mining.md` and `plans/x-mosaic.md`.

## Workflow

This is a single-PR audit (potentially split into 3-5 PRs by section
if findings are large). Workflow:

1. Per upstream, run Steps 1-4 above. Capture findings in working notes.
2. After ~5-10 upstreams audited, draft an UPSTREAM_OPPORTUNITIES.md
   section update.
3. Open a PR:

   git checkout -b ai/upstream-audit-<date>
   <stage UPSTREAM_OPPORTUNITIES.md>
   git commit -m "Upstream parity audit — <upstream-list>" \
     --author "Joe Huss <detain@interserver.net>"
   git push -u origin ai/upstream-audit-<date>
   unset GITHUB_TOKEN
   gh pr create --title "Upstream parity audit — <upstream-list>" \
     --body "<see template below>"
   gh pr merge <n> --merge --delete-branch
   git checkout master && git pull --ff-only

4. Stop. Report findings + wave recommendations. Wait for direction.

PR body template:

  ## Summary

  Upstream parity audit covering <upstream-list>.

  Findings:
  - ✅ <count> features already present
  - 🟡 <count> partial / variations
  - 🔴 <count> port candidates
  - ⚪ <count> not applicable

  Recommended port wave:
  1. <item> — <effort estimate, benefit summary>
  2. <item> — ...

  ## Test plan

  - [x] UPSTREAM_OPPORTUNITIES.md syntactically valid markdown
  - [x] All cross-references to MATCHUPS.md / CONVERSION.md correct
  - [x] No 🔴 items duplicate already-tracked plans/ entries

## Conventions

This audit is documentation-only. The only file to modify is
`UPSTREAM_OPPORTUNITIES.md`. If during research you discover a bug or
gap that's not classified by this audit (e.g. a SugarCraft
implementation difference from upstream that's actually a bug), do
NOT fix it here — flag it separately for the user to triage.

If a port candidate looks substantial enough to warrant its own plan
file, propose it in the PR description and let the user decide whether
to draft `plans/<new-plan>.md`. Don't draft the plan inside this audit.

## Guard rails

- Never push to master directly.
- Never modify any file other than `UPSTREAM_OPPORTUNITIES.md` (and
  optionally a per-lib `CALIBER_LEARNINGS.md` if the audit reveals a
  pattern worth recording).
- Never start writing code based on findings in this audit — output is
  research, code is follow-up work.
- Don't recommend porting upstream changes that conflict with documented
  decisions (skip rules in `evaluated-skipped.md`, deferred items in
  `CONVERSION.md`).
- Caliber sync per CLAUDE.md "Before Committing".

## Deliverable per audit PR

1. Upstreams covered (list)
2. Total findings by category (✅/🟡/🔴/⚪)
3. Top 3-5 port candidates with effort + benefit
4. Smaller-fix bundle list (items that fit in one cleanup PR)
5. Plan candidates surfaced (if any)
6. PR URL + merge confirmation

Then stop.
```

---

## Tips for running this audit

- This audit needs **GitHub API access** — agents without it (web
  Claude / web ChatGPT without connectors) can't run `gh api`. For
  those, you'd need to fetch the data yourself and paste it in. Cursor /
  Aider / opencode work directly.
- Refresh cadence per `UPSTREAM_OPPORTUNITIES.md`: quarterly or before
  planning a port wave. Don't over-run this.
- The default scope (every upstream in MATCHUPS.md) takes 1-2 hours per
  agent run if all upstreams have activity. Split into "Charmbracelet
  core" / "Charmbracelet apps" / "third-party libs" PRs if findings
  are large.
- For high-stakes upstreams (bubbletea, lipgloss, bubbles, huh) read PR
  diffs in detail. For low-stakes (already-quiet repos, niche libs)
  title scan is enough.
- The wave recommendation at the end is the most valuable output —
  agents tend to weight findings equally. Push back on wave
  recommendations that don't account for benefit/effort ratio.
- If the audit surfaces something that should clearly become a plan file
  (e.g. a coordinated multi-lib feature wave), the agent should
  describe the plan in the PR but NOT draft the file. The user decides
  whether to spin up a `plans/<new>.md`.
