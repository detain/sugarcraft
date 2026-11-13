# P5 gate audit, cluster C: fleet / process / site + remaining (2026-11-13)

Read-only audit against the live tree, `git log`, `gh run` (read-only) and `git ls-remote`. Source packet:
`reviews/P5-GATE-PACKET-2.md` (compiled shell-less; several "unlanded"/"parked" claims are stale, noted per item).
Owner standing rule applied: BUILD/FIX arm wins over disclaim; outward-facing/irreversible GitHub legs are listed as
OWNER ACTION with exact commands, never planned for execution.

## 0. Headline findings (read these first)

1. **master CI has been red since 2026-10-30 (last green SHA `364a52876`).** Every completed CI run on master since
   then is `failure`; `Latest PHARs` (`ci-gate`, fail-closed, polls up to 240 min for green CI at the exact pushed
   SHA) therefore can never publish. WF-CURE's workflow edits (`191ccd566`, 2026-11-12: inject, install, verify,
   restore, Guard-2 precondition, sidecar via `--out`) ARE landed but have never executed to green. The remote still
   carries 19 frozen `<app>/dev` tags at `f1c90bb34` (2026-11-04) and ZERO `<app>/latest` tags (the rename landed as
   `6b6a939ff`; the commit `d58e3b5` named in the brief does not exist in this clone), so README/self-update text that
   says `candy-top/latest` points at a release that does not exist yet. Root cause of the failed/pending Latest PHARs
   runs is CI health + gate design, not the cure wave.
2. **Concrete CI-red causes found (CI run 43480159507, master):**
   - `Generated docs pages` + `tools/ guards` (22 failures): root `README.md:82` (sugar-releases row) says
     "rolling **latest** channel" (changed by `6b6a939ff`) but `prompt_kit/root-rows-seed.json:884` still says
     "rolling **dev** channel", so `tools/check-root-rows.php` fails. A `sugar-demo` row is also missing from the seed
     corpus (`FAIL index sugar-demo: missing`). One seed re-pin + one seed row.
   - `Windows smoke - candy-code`: ConPTY `UpdateProcThreadAttribute ... error 87` (pty lane, not this cluster).
   - `candy-git-server` Test 8.3/8.4/Coverage: 1 failure (`233152 is identical to 328914`) plus a git protocol fatal
     (in-flight git-server lane; not this cluster).
3. **S-N16 is IN FLIGHT in the working tree by another session** (uncommitted `candy-top/lang/en.php` + 11 panel
   views: `unit.*`, `mem.title.*_short`, `net.*_short`, `proc.col.pid` colon-drop). candy-top suite right now is
   **2353 tests / 55 failures** (OverlayGoldenTest, FrameSnapshotTest, TtyModeFrameTest, PastelThemeFrameTest): goldens
   not yet re-blessed by that session. Do NOT touch; foreign WIP, not a regression of landed work.
4. **S-P01 and S-P03 are findings with wrong premises** (section 1): FreeBSD `sysctl -e` prints name=value (values-only
   is `-n`); the IPMI redundancy arms `0x18->sufficient`, `0x20->insufficient`, `0xC0->degraded` already match the
   IPMI v2.0 generic-0Bh offset table. Neither needs hardware; both become "pin the truth from documented formats".
5. The packet's "S-O17/S-O18/S-O22 unlanded" is stale: all three landed (`b26c06f74`, `2db4938a3`, `23a83d799`).
   S-O02 (`4af4c14e1`), S-N12 (`fd9be44f1`), S-N19 (`387c03177`), S-O14 (`f6325e477`), S-P23 (`765705d86`),
   S-O01 (`37f1289f3` + `74d5c671d`), S-O03, S-O08, S-N01 also landed.
6. Register ID collisions to key around: "OG-8" = sugar-table GIF leg = sugar-dashboard D-60 = repo-tooling RT-32;
   "SM-S19" = sugar-mouse GIF leg = sugar-markdown chunked-compaction test (landed `271d9b21c`, unrelated);
   S-O20/S-N02/S-N11/S-X05/S-P0x exist in BOTH candy-top and candy-code queue rows. Key on `target:id`.

## 1. candy-top

### Verdict table

| Row | Live state | Decision | Effort | Collision group |
|---|---|---|---|---|
| S-P01 | untouched; premise wrong | BUILD pins + fixtures (no hardware) | S | G-LIVEPROBE |
| S-P02 | untouched | BUILD async probe (3 PRs) | L | G-LIVEPROBE |
| S-P03 | untouched; premise half-wrong | BUILD spec table + worst-severity precedence | S | G-REDUNDANCY |
| S-P04 | untouched | BUILD | S | G-LIVEPROBE |
| S-P07 | untouched | BUILD | S | G-LIVEPROBE |
| S-P12 | untouched | BUILD | S | G-FBSD-SMALLS |
| S-X06 | docs half NOT landed; pin flaky | BUILD sugar-pty pin + comment truth | M | G-BIN |
| S-O01 / dev-phar | disclosure landed; cure landed, unproven | see section 2 | - | G-WORKFLOWS |
| S-O02 (G1) | LANDED `4af4c14e1` | none (Darwin/OpenBSD collectors optional later) | - | - |
| S-N12 (G3) | LANDED `fd9be44f1` | none | - | - |
| S-N16 (G4) | IN FLIGHT foreign WIP | WAIT, then verify/re-bless | - | G-ENLANG |
| S-N11 (G5) | untouched (`lang/` = en.php only) | BUILD full wave after S-N16 commits | L | G-ENLANG |
| S-X14 (G7) | untouched | BUILD derive-from-profile | M | G-BIN |
| S-O14 (G8) | claim-side LANDED `f6325e477` | roster enrol rides S-X05 | - | G-AFFECTED |
| S-P23 (G9) | LANDED `765705d86` | none | - | - |
| S-N19 (G11) | LANDED `387c03177` | none | - | - |
| S-N02 (G10) | untouched; 73 level-5 findings measured | BUILD fix-all, no baseline | S-M | G-PHPSTAN |
| S-N03 | untouched | merge into S-N02 | in S-N02 | G-PHPSTAN |
| S-O17/18/22 | LANDED | none (packet stale) | - | - |
| S-O20 (site lede) | untouched | BUILD | S | G-DOCSGEN |
| S-X05 | untouched | BUILD audit + enrol MACOS_LIBS | M | G-AFFECTED |

### S-P01 FreeBSD `sysctl -i -e`

(a) Untouched. `LiveProbe.php:55` runs `sysctl -i -e <names>`; `Sysctl.php` docblock + `parse()` regex
`^name(?:=|:[ \t]?)value` accepts BOTH forms and `ProbeTest::testParsesBothSysctlOutputForms` pins the parse. No argv-shape
pin exists (tests grep for `'-i'` hits only netstat). (b) The finding claims FreeBSD `-e` is values-only. FreeBSD
sysctl(8): `-e` separates name and value with `=`; values-only is `-n`; `-i` ignores unknown OIDs. So the collector is
not dead-on-arrival; the real gap is that nothing pins argv or the documented output grammar, and `README.md:648` says
captures are pending. (c) BUILD without hardware: lock behaviour to the documented man-page formats. (d) Plan:
- `tests/Collect/FreeBsd/SysctlOutputFormatsTest.php`: man-page-derived fixtures for `-e` (`kern.ostype=FreeBSD`),
  default (`kern.ostype: FreeBSD`), multi-token values (`kern.cp_times`, `vm.loadavg: { 0.32 0.33 0.25 }`),
  `dev.cpu.0.%desc`, `-i` omission of unknown OIDs, blank/garbage lines, values containing `=`/`:` (the regex takes the
  first separator; pin `hw.model=Intel(R) CPU @ 1.80GHz` and a value like `a=b`), and a `-n`-style values-only reply
  that must yield `[]` (not corrupted keys).
- Argv pin: expose `LiveProbe::sysctlArgv(array $names): list<string>` as a pure static seam (same idiom as
  `childEnvFor`), pin `['sysctl','-i','-e',...$names]`; point the `Sysctl.php` docblock at the man page.
- README:648 becomes "output grammar is pinned to the documented sysctl(8) formats; no live-host capture exists".
- Effort S. Group G-LIVEPROBE, strict order P01, P02, P04, P07 (P17 already landed out of order).
- Optional later (non-blocking): a real capture replaces the `*.synthetic.txt` fixtures.

### S-P03 IPMI redundancy bitmask

(a) Untouched. `Redundancy.php:21-36`; pins at `IpmiReadingsTest.php:113-125`. (b) IPMI v2.0 generic reading type 0x0B
offsets: 0 Fully Redundant, 1 Redundancy Lost, 2 Redundancy Degraded, 3 Non-redundant: Sufficient from Redundant,
4 Non-redundant: Sufficient from Insufficient Redundancy, 5 Non-redundant: Insufficient Resources, 6 Degraded from
Fully Redundant, 7 Degraded from Non-redundant (bitmask `0x01..0x80`). The code's `0x18` sufficient, `0x20`
insufficient, `0xC0` degraded are spec-correct; the finding's "0x10 = insufficient" and "0x20 spec-reserved" are wrong.
Real defects left: (i) lowest-set-bit wins (a multi-bit `0x21` renders Full; should be worst-of), (ii) no exhaustive
pin so the misreading can recur. The `$cols[1]` question: real-BMC captures already in the tree (`tests/fixtures/ipmi/
kvm521`, `skynet2`) show discrete rows carry the event bitmask in the reading column (`0x1`, `0x2`), and
`IpmiReadings.php:89` prefers `fromText` from `sdr elist` whenever text exists, so bitmask is fallback only. No fixture
BMC has a 0x0B sensor (grep `redundan` empty). (c) BUILD spec table + worst-severity precedence. (d) Plan:
- `Redundancy::fromState`: collect every asserted offset 0..7, return the member with highest `severity()` (Lost and
  Insufficient over Degraded and Sufficient over Full), ties by lowest offset; bits above `0x80` ignored with a docblock
  naming the spec ceiling (do not throw: BMC data is untrusted).
- `tests/Collect/Ipmi/RedundancySpecTableTest.php`: eight single-bit rows with the spec offset NAME in each message;
  combined cases (`0x03`, `0x21`, `0x12`); `0`/`null`/`0x100` return null; `fromText` parity for the `ipmitool` text of
  every offset.
- Synthetic `sensor-list`/`sdr-elist` HP "Fans" redundancy pair under `tests/fixtures/ipmi/spec-0b/` (named
  `.synthetic`) + one `IpmiReadings` integration test; code comment records the column decision.
- Effort S. Group G-REDUNDANCY (Redundancy.php + IpmiReadingsTest.php only).

### S-P02 / S-P04 / S-P07 / S-P12 (dependents; none needs hardware)

- **P04 (S):** `LiveProbe::resolve()` (`:148-163`): static per-name cache and `AbsoluteSearchPath::directories()`
  (landed with S-C06/S-C07) with `DIRS` prepended. Tests: a relative `./ps` PATH entry is never selected; cache hit
  does no stats.
- **P07 (S):** byte cap in the `run()` read loop (`:83-94`): 8 MiB, SIGKILL, return null (mirror `AsyncCommand`
  `TooLarge`). Test with a `yes` child.
- **P12 (S):** `FreeBsd/ProcList.php:211` regex loses `comm` with spaces: split the fixed leading numeric columns and
  take comm/args by position from the right; fixture `ps-comm-with-spaces.synthetic.txt`; `FreeBsd/Temp.php:84` filter
  `> UNMEASURED` becomes sentinel identity so sub-zero celsius survives; test `-12.5C`.
- **P02 (L):** collapse 8-11 sequential fork+wait per tick: add `Probe::launchAsync(array $argv): PromiseInterface<?string>`
  over `AsyncCommand::launch` (landed; `withSpawnFactory` from S-P18 makes it testable with no FreeBSD), collectors adopt
  pending-swap (the `GpuHold`/`SmiProcess` idiom); `FixtureProbe::launchAsync` returns resolved promises; acceptance via
  a slow-spawn factory proving input+render never block past one deadline. Three PRs: (1) seam + Cpu/Memory/Freq/Temp,
  (2) Net/DiskIo/Mounts, (3) ProcList/Battery + README measured-ceiling removal; record R6 caller enumeration
  (`SmiProcess::command`, `Ipmi::blocking`).

### S-X06 SIGTERM end-to-end pin

(a) The packet's "signal-docs half landed" is false on disk. `bin/candy-top:344-346` still says "sugar-core's Program
traps only SIGINT" and `App.php:1693-1699` still says "SIGTERM / SIGHUP (trapped in bin)", while
`sugar-core/src/Program.php:2486-2495` installs SIGINT/SIGTERM/SIGHUP itself at `run()` (overriding the pre-run bin arms).
The end-to-end pin does not exist. (b) Unfinished: stale comments on the safety-critical seam, no pin, the `script -q -e -f`
wrapper flake. (c) BUILD a deterministic pin and drop the wrapper. (d) Plan:
- Fix both comments (bin arms cover only the window before `run()`: config load, size probe).
- `tests/Cli/SignalExitTest.php`: add `sugarcraft/sugar-pty` to `require-dev` (require bump only; verify
  `php tools/check-path-repos.php --no-lib-path-repos`), spawn `bin/candy-top --fake` on a real pty (`Pty`, exit via
  waitpid, no `script(1)`); barrier = read pty output until alt-screen enter `\e[?1049h` appears (condition-based, no
  sleep); signal the child pid; wait under a hard deadline; assert the measured exit code (pin once), output contains
  `\e[?1049l`, and the `--config` temp file was saved. Repeat for SIGHUP. Gate with `requirePtySyscalls()`; follow the
  LoopPin-before-HangWatchdog bootstrap law if loop timers are armed.
- Fallback if sugar-pty cannot be a dev-dep: pin the seam instead (Program registers SIGTERM, `App::exitSave()` runs
  after a signal-driven stop via a simulated `kill()`), plus the comment fix.
- Effort M. Group G-BIN (`bin/candy-top`: X06, X14, X12) + `composer.json` require-dev.

### G-gate rows

- **S-N16 (G4):** foreign WIP. When committed, verify: grep sweep shows no raw `' W'`/`'%'` literals in views; goldens
  re-blessed and disclosed.
- **S-N11 (G5):** BUILD the full wave (owner rule beats the de-only default), after S-N16 commits (key shapes frozen).
  Seed `de.php` first, then the LOCALES.md set to candy-code parity (11-16 packs). Test: key-parity guard across locales
  + `LANG=de_DE.UTF-8` smoke render of cpu/mem/net/proc; README:752 stage wording flips. L; split de+es+fr /
  it+pt+pt-br+nl / ja+ko+zh-cn+zh-tw+ru. Group G-ENLANG.
- **S-X14 (G7):** BUILD derive-from-profile. `App::programOptions(Config)` ignores the colour profile; add
  `programOptions(Config, ColorProfile $ambient)`: `NoTty`/dumb gives `useAltScreen:false`, `MouseMode::Off`, no
  sync/2026/2027 modes; `bin/candy-top` passes the ambient profile and prints a one-line stderr notice; README
  Requirements documents it. Test: pty child with `TERM=dumb` asserts zero `1049|1002|1006|2026|2027` bytes (reuse the
  S-X06 harness). M. Group G-BIN.
- **S-N02 (G10):** BUILD fix-all, no baseline. Measured: `phpstan analyse` level 5 over `candy-top/src` (temp config in the
  session scratchpad, `treatPhpDocTypesAsCertain: false`) = **73 findings**, mostly `?->` on non-null and "never returns
  null" return types; the likely-real ones to regression-test: `App.php:1020` `@param` unknown `$sampled`,
  `Help.php:297/300` `?? always exists`, `AmdSysfs.php:133-140` and `IntelSysfs.php:103-122` offset-does-not-exist (verify
  real shape bugs), `ProcView.php:796` `int<0,7> >= 8` always false, `ProcPanel.php:1305` strict compare always false,
  `Lang.php:17` invalid `@extends`. Land `candy-top/phpstan.neon` (shape of `candy-sql/phpstan.neon`, level 5, `src` only)
  in the same PR that clears all 73; absorbs S-N03. Verify the `ci.yml` phpstan job installs phpstan itself
  (`vendor/phpstan` is absent locally). Do after the foreign S-N16 WIP commits (same panel files). S-M. Group G-PHPSTAN.
- **S-O14 / S-X05 (G8):** claim-side landed. Audit: 21 test files read live `/proc`/`/sys`, 27 skip-guards exist. BUILD:
  add `candy-top` to `MACOS_LIBS` (`scripts/affected-libs.php:35`) with OS-family skip guards on live-collector tests, run
  once on CI and read the result; Windows enrolment after the pty lane's ConPTY fix. README tested-platform bullet in
  the same commit. M. Group G-AFFECTED (repo-tooling owns the file; serialize).
- **S-O20 (site lede):** `docs/_data/candy-top.body.html:1` link text "SugarCore" becomes `sugar-core`; regen with
  `php tools/gen-docs.php` (the only other hit is generated `docs/lib/candy-top.html`; never hand-edit); assert
  decoded-block equality. S. Group G-DOCSGEN.

## 2. phar-fleet WF-CURE (queue row 2)

(a) Landed: `191ccd566` (restore tree + scoped sidecar before build-phar); `phar-latest.yml:236-300` carries inject
(`--out` outside worktree), `composer install`, `pin-siblings verify`, manifest restore, Guard-2 porcelain precondition.
Rename dev to latest: `6b6a939ff` (workflow, tag, self-update channel, docs for 19 apps). Per-app legs landed (kv
`f96a2b190`, tetris `fc26f3b80`, ansi-inspect `904f2770f`, top S-O01).
(b) Unfinished/broken: the channel has not published since the cure: recent `Latest PHARs` runs failed or are pending
behind `ci-gate` (run 43458724519: ci-gate burned 2h05m polling a CI that never went green); `concurrency: phar-latest`
with `cancel-in-progress: false` queues each push behind the previous poll. Remote: 19 stale `<app>/dev` tags, no
`<app>/latest`. `tools/tests/ReleasePublishPostureTest.php` pins the gate shape.
(c) BUILD. (d) Plan:
1. Unblock CI (precondition): re-pin `prompt_kit/root-rows-seed.json:884` ("rolling latest channel") + add the
   `sugar-demo` seed row (S, G-ROOT-SEED; sole cause of two CI jobs, a rename leftover); pty lane fixes ConPTY error 87;
   git-server lane fixes its failing test. No workflow design can publish honestly while CI is red.
2. Redesign the gate (M, G-WORKFLOWS): replace push-trigger + 240-minute poll with
   `on: workflow_run: workflows: [CI], types: [completed]` filtered to `conclusion == 'success'` on master (+ keep
   `workflow_dispatch`); build `github.event.workflow_run.head_sha`; affected range = last published `<app>/latest`
   `.phar.commit` sidecar to head (fallback `--all`); delete the poll job. OD-7 intact (the SHA built is the SHA CI
   blessed). Re-pin `ReleasePublishPostureTest` in the same commit. Rejected alternative: walk back to the newest green
   ancestor (builds a SHA other than the pushed one).
3. Dry-run (S): `workflow_dispatch` with `all: true` once CI is green; verify the `candy-top/latest` release carries
   `.phar.commit` == tested SHA and metadata `dev-<SHORT_SHA>`; smoke `candy-top.phar self-update --channel=latest`.
4. `candy-top/README.md:70-76` says the rolling `candy-top/latest` release carries the 2026-11-04 build, false on the
   remote until publication; fix wording in the step-3 PR (S, G-TOP-README).

OWNER ACTION (outward-facing, not for a lane), only after the first green `Latest PHARs` run has created
`<app>/latest` and its sha256 verified:
```
unset GITHUB_TOKEN
for app in candy-code candy-codeshot candy-codetime candy-files candy-flappy candy-gifview candy-git-server candy-git \
  candy-image candy-kv candy-mail candy-mdview candy-minesweeper candy-scriptui candy-sql candy-ssh-launcher \
  candy-tetris candy-top candy-vcr; do
  gh release delete "$app/dev" --cleanup-tag --yes
done
```
Optional bridge (NOT recommended: it relabels pre-hardening bytes): `git push origin
f1c90bb34a57e0d9652f440636dab4df1803ab77:refs/tags/$app/latest && gh release edit "$app/dev" --tag "$app/latest"`.

## 3. FLEET GIF law (#38)

(a) Live: AGENTS.md:52 ends "Don't commit GIFs."; CLAUDE.md:57 says "GIFs are committed";
`.claude/rules/vhs-tape.md:13` says the `commit` job in `vhs.yml` does it; `prompt_kit/CONTEXT.md:658` already reads them as
non-contradictory ("the first is addressed to you, the second describes CI"). Tree: **296 tracked `.vhs/*.gif`** (426 `.gif`
repo-wide, ~33 MB); `vhs.yml` renders and commits them (`2bae883c7 vhs: regenerate demo GIFs`); READMEs/site hotlink
`raw.githubusercontent.com/detain/sugarcraft/master/<slug>/.vhs/<demo>.gif`, guarded by `tools/check-docs-assets.php`.
Git-rm would break every tile.
(b) Only the sentence is wrong (reads as a prohibition; really "authors do not hand-render").
(c) Resolution, in-repo and reversible (single revert): amend, do not git-rm. Replace "Don't commit GIFs." in AGENTS.md:52
with: "Never hand-render or hand-commit GIFs: the `commit` job in `vhs.yml` renders `<slug>/.vhs/*.tape` through candy-vcr and
commits `<slug>/.vhs/*.gif`; those committed files are what READMEs and the site hotlink (`raw.githubusercontent.com`), so a
lib's GIFs stay tracked." Same commit: soften "quote ALL values" to "quote string values; bare numeric sizing args are
tolerated fleet-wide" (closes CH-56's 068 half; fleet tapes carry bare numerics; one line beats editing dozens of tapes;
align `.claude/rules/vhs-tape.md:8` and `.claude/skills/add-library-checklist/SKILL.md:25`).
(d) Legs closed (no lib edits, rule text only): OG-8/sugar-table-065 (wont_fix, sugar-table.md:609/:626); SPR-36
`GATED-LEG:gif-056` (step landed `452c65e67`, drop the leg marker); SM-S19/G9 sugar-mouse-053 (execute the "AGENTS
exception" branch = no-op, 3 GIFs stay); candy-files RO row (candy-files.md:481); sugar-toast CONV-07; ssrv note
(sugar-ssh-server tracks `showcase.gif`, `spawn-bash.gif`); CH-56 (sugar-charts 068+069; the AGENTS diff is its
acceptance). Group G-ROOT-AGENTS (one writer). Effort S.

## 4. site-docs SD-S34 identity sentence

(a) Untouched (SD-S33/S35 landed around it). Inconsistent framing live: `AGENTS.md:3` and `CLAUDE.md:3` "Charmbracelet
ecosystem ports"; root `composer.json:3` "PHP ports of the Charmbracelet TUI ecosystem"; but `docs/MATCHUPS.md` Inspirational
section ("reference projects supplied the ideas, not the code"), `README.md` Credits ("reimagined here for PHP 8.3+"),
`CONTRIBUTING.md` ("original work"); per-lib docblocks cite `Mirrors charmbracelet/<repo>.<Method>`. Related count drift:
MATCHUPS says 42 libraries + 22 apps (64), root composer says "46 libraries", CLAUDE.md/AGENTS.md say 40 libs.
(c) BUILD: ports posture (the Mirrors citations make "not the code" unprovable). One sentence, verbatim on all surfaces:
"SugarCraft is a set of PHP ports of the Charmbracelet TUI ecosystem: each library reimplements its upstream's API in
idiomatic PHP 8.3+ (the upstream is cited in every `Mirrors charmbracelet/<repo>.<Method>` docblock), and where no upstream
exists the library is original work."
(d) Plan: edit AGENTS.md:3 (CLAUDE.md:3 mirrors it), `docs/MATCHUPS.md` header + Inspirational intro, `README.md` Credits,
`CONTRIBUTING.md` original-work paragraph; delete the contradicting "ideas only"/"not the code" phrases; because MATCHUPS
changes, keep the root README row and `docs/libraries.html`/`apps.html` cards in sync and rerun
`php tools/check-root-rows.php`; acceptance: one grep returns exactly four verbatim hits (quote them in the commit body);
fix the library-count drift in the same sweep. S. Group G-ROOT-AGENTS (after the GIF edit) + G-ROOT-SEED.

## 5. sugar-table OG families (register `reviews/annotations/sugar-table.md:619-627`)

Live: only ST-72 landed (`6bc0f83cc`). Landed prereqs: ST-15 `0f9268eee`, ST-17 `ff43a142b`, ST-18 `734ed58ab`, ST-23
`9ebaea18a`, ST-50 `8c16c5513`. Untouched: ST-02, 19, 20, 22, 36, 37, 38, 59, 60, 61, 68, 69, 70, 71 (suite not rerun).
`src/Table.php` is 3319 lines with 24 PascalCase publics. All BUILD arm; one collision group **G-TABLE** (order below):
1. **ST-02 / OG-1 (S-M):** rename arm, matching sugar-datepicker, which renamed outright (`1db669261`; PHP method
   dispatch is case-insensitive so Pascal callers keep resolving). Rename the 24 publics (SelectNext, SelectPrevious,
   SelectPage, NextPage, PreviousPage, SortBy, ClearSort, Filter, ClearFilter, ClearAllFilters, ClearSearch, Columns,
   Rows, CurrentRow, CurrentRowData, SelectedRow, TotalRows, TotalPages, SelectedIndex, CurrentPage, PageSize,
   PageFooter, RowsFooter, View); measured: zero lcfirst collisions. Add `tests/ApiAliasTest.php` (mirror datepicker's),
   flip README :1116-1131 to "camelCase house API; Pascal spellings still resolve", CALIBER note, check the
   `Model::view` contract. Prereq for everything below.
2. **ST-19 / OG-2 (M):** `withZebraStyleEven/Odd(string|Style)` replacing the hardcoded `'30;47'` (`Table.php:113`);
   suppress the stripe under the cursor row; pin selected+striped == selected-unstriped.
3. **ST-20 / OG-3 (M):** build BOTH arms: `renderedLineCount(): int` and `withMaxLines(int)` truncation; README states
   `withViewportHeight(n)` counts rows.
4. **ST-22 / OG-4 (M):** case-insensitive dual-spelling key names (keep `KEY_*` consts), `handleScrollKey` with
   `handleKey` delegating alias, and the KeyEvent overload with a `sugarcraft/sugar-input` `require` bump (verify
   `check-path-repos --no-lib-path-repos` and the root closure; if it creates a dependency cycle, ship dual-spelling only
   and record why).
5. **OG-5 family (L total, build the features, not notes):** ST-36 natural sort (`strnatcmp`) + per-column comparator
   hook; ST-37 filter predicate callable; ST-69 static footer + `Column::withFormatString`; ST-68 pagination wrap flag;
   ST-70 overflow arrows + `scrollLeft/Right`; ST-71 `withRowSeparator(bool)`; ST-38 selection set (DD-scale, its own PR).
   README "Divergences from upstream" lists only what stays unported (section created once by ST-36).
6. **ST-59 / OG-7 (M):** solid continuous selection band (reverse in-row glyphs); bordered-row goldens move (disclosed
   re-bless). 7. **ST-60 / OG-6 (S):** keep `'-'`; README adds the `'·'`/`'--'` suggestion + control-char note; zero golden
   movement. 8. **ST-61 / OG-9 (M):** arm A clamp to declared width plus opt-in `withStrictPercent(bool)` throw (arm B).

## 6. sugar-toast shape-gates (suite green: 452 tests / 2339 assertions)

Default legs landed (VALID `a96f2304`, LAYOUT `76c70a8ba`, CHROME `bb6702268`, ASCII `1d777e84f`, FOLDROW/XSET/XOFF
default legs, FRAME crown); `src/ToastFrame.php` exists (`Toast::frame()` `:1238`). Remaining are the gated arms, all BUILD,
all in `src/Toast.php` so one group **G-TOAST** (single writer, this order):
- **XOFF (S):** additive `Position::xOffsetClamped()` (arm b, non-breaking) + keep the pinned negative `xOffset()`; pins for
  9 anchors x oversize; docblock points hosts at the clamped accessor.
- **VALID (S):** keep both doors but route them through one private validator so ctor and door messages cannot diverge
  (settles the "delete the door throw" ambiguity without losing the friendlier wording).
- **FRAME-inset (S):** `ToastFrame::placeAt(int $col, int $row)` (026's optional leg); test `boxRows()`+`placeAt`
  reproduces composited rows.
- **ASCII (M):** route 6 border + 2 bar glyphs through `SymbolSet` (`+ - |`, `# .`); permanent disclosed Ascii golden
  re-bless, non-Ascii goldens asserted byte-stable; README :20/:472.
- **LAYOUT (M-L):** fold-to-fit: cap painted stack to the viewport budget, surplus into a `+N hidden` row reusing the
  action-fold idiom (~`Toast.php:1511-1519`); fitting-subset bytes preserved; 9-anchor pins; repro `BottomRight`, 6 alerts,
  `view('',60,5)` returns at most 5 rows.
- **CHROME (M):** `toast.more`/`toast.truncated` keys across all 17 `lang/*.php`; TST-PARITY guard covers them;
  re-pin `ToastMessageCapTest` goldens (disclosed); README :250 becomes fully true.
- **FOLDROW (M, after LAYOUT):** the fold row becomes a real rect in `ToastFrame::actionRects()` firing an expand event
  (state + README keyboard contract).
- **CUSTTYPE (M):** build the registry seam: immutable `AlertTypeRegistry` mirroring bubbleup's `AlertDefinition` (key,
  prefix/glyph, colour), `Toast::withTypeRegistry()`, string types resolve registry-first then built-ins; rename the
  misnamed `ToastCustomTypeTest`; README Alert Types section.
- **XSET (S, deliberate non-retrofit):** adding `$XSet` companions beside the existing `persistent` bool pair would change
  public shape for no observable gain (the probe leg shows the confusion surface empty). Build = the AGENTS.md rule
  amendment ("nullable where null and unset are observably different need an `$XSet` sentinel"; sugar-toast the cited
  exemplar) in G-ROOT-AGENTS, plus the existing probe test. State this deviation in the commit.

## 7. spotlight S-08 / S-14

No object: no queue rows, no annotation file, no lib/app dir (tree grep hits only `reviews/P5-GATE-PACKET-2.md` and one
unrelated word in `ansi/README.md`). Close as non-existent; a real "spotlight" would be a new-lib proposal, not a gate.

## 8. Welder duties / trio rows

- **scriptui DiagnosticsTest durations row: void.** `scripts/parallel-tests-durations.tsv` is candy-code-only (2123
  rows, all `candy-code/tests/...`); `candy-scriptui/tests/Runtime/DiagnosticsTest.php` is not sharded. Verified: on-disk
  `candy-code/tests/**/*Test.php` (2123) == TSV rows (2123), zero unlisted, zero stale.
- **sugar-toast suite-figure row: void.** Nothing in the repo pins sugar-toast's 396/2093 to 452/2339 figure (grep of
  README/json/php/tsv/yml empty). candy-code's trio (`suite-figure.json` 33,094 tests, README headline `:3076`) was
  refreshed in `edfc2d6cd` and no candy-code test commits have landed since. No welder action; close both rows in
  `reviews/OWNER-GATES.md` section C. (candy-top is outside the trio, so none of this cluster's work moves it.)

## 9. ssrv / SPR-36 / SM-S19

All folded into the GIF law (section 3): ssrv (sugar-ssh-server tracks two GIFs), SPR-36 (step landed `452c65e67`, only the
056 leg open), SM-S19 (sugar-mouse G9, 3 tracked GIFs). Zero lib edits; they close when item 2 below lands.

## 10. Issues noticed (red/drift/broken)

1. master CI red 14 days; Latest PHARs failing/queued (section 0).
2. `prompt_kit/root-rows-seed.json:884` stale after the channel rename; `sugar-demo` seed row missing.
3. candy-top working tree: S-N16 foreign WIP gives 55 red goldens until committed.
4. `bin/candy-top:344` and `src/App.php:1697` signal comments factually stale.
5. `candy-top/README.md:70-76` claims a `candy-top/latest` release exists; remote has only `candy-top/dev` at `f1c90bb34`.
6. Package counts disagree: CLAUDE.md 40 libs, MATCHUPS 42+22, root composer.json "46 libraries".
7. `.claude/rules/vhs-tape.md:8` ("quote even numerics") vs fleet tapes with bare numerics vs AGENTS "quote ALL".
8. The packet's candy-top "unlanded" list (S-O17/18/22) is stale; `reviews/EXEC-STATUS.md` absent as the packet says.

## 11. Schedule-able work list (priority / dependency order)

| # | Work item | Effort | Depends on | Collision group |
|---|---|---|---|---|
| 1 | Re-pin `root-rows-seed.json` sugar-releases row + add `sugar-demo` row; run `tools/check-root-rows.php` | S | none | G-ROOT-SEED |
| 2 | AGENTS.md batch: GIF-law sentence + vhs quoting wording + XSET wording + SD-S34 identity sentence (4 surfaces) + `.claude/rules/vhs-tape.md` + add-library-checklist; closes 7 GIF legs | S | none | G-ROOT-AGENTS (one writer) |
| 3 | WF-CURE: `Latest PHARs` via `workflow_run`-on-green-CI + `ReleasePublishPostureTest` re-pin; dry-run; candy-top README disclosure fix | M | CI green (item 1 + pty + git-server lanes) | G-WORKFLOWS, G-TOP-README |
| 4 | candy-top S-P03 spec-table redundancy | S | none | G-REDUNDANCY |
| 5 | candy-top S-P01 pins, then S-P04, then S-P07 | S+S+S | none | G-LIVEPROBE |
| 6 | candy-top S-P12 FreeBSD smalls | S | none | G-FBSD-SMALLS |
| 7 | candy-top S-O20 site lede + regen | S | none | G-DOCSGEN |
| 8 | candy-top S-X06 comments + sugar-pty SIGTERM/SIGHUP pin | M | none | G-BIN |
| 9 | candy-top S-N02 (+S-N03) phpstan level 5, fix-all | S-M | foreign S-N16 WIP committed | G-PHPSTAN |
| 10 | candy-top S-X14 TERM=dumb derive modes | M | item 8 (harness) | G-BIN |
| 11 | candy-top S-X05 enrol MACOS_LIBS (Windows later) | M | none (Windows: pty lane) | G-AFFECTED |
| 12 | candy-top S-P02 async FreeBSD probe (3 PRs) | L | item 5 | G-LIVEPROBE |
| 13 | candy-top S-N11 locale packs | L | foreign S-N16 committed | G-ENLANG |
| 14 | sugar-table: ST-02 rename, then 19, 20, 22, OG-5 family, 59, 60, 61 | L (8 PRs) | ST-02 first | G-TABLE |
| 15 | sugar-toast: XOFF, VALID, FRAME-inset, ASCII, LAYOUT, CHROME, FOLDROW, CUSTTYPE, XSET wording | L | none (single writer) | G-TOAST |
| 16 | Close void rows: spotlight S-08/S-14, scriptui/toast welder rows; update `reviews/OWNER-GATES.md` | S | none | docs only |

Owner actions: delete the 19 stale `<app>/dev` releases and tags after the first green `<app>/latest` publish (commands in
section 2). Nothing else in this cluster is outward-facing.
