# SugarCraft keymaps

A fleet-wide index of where each app's keys are documented. This page is
**links only, on purpose**: every app's key table is generated or guarded
where it lives (for example candy-code's is pinned by
[`KeyBindingDriftTest`](../candy-code/tests/Commands/KeyBindingDriftTest.php)
against its registry), and restating a table here would fork it — the fork
would drift, and the drift would lie. So grab the chord list from the
authoritative page, never from a copy.

## Apps

| App | Authoritative key documentation |
|---|---|
| **candy-code** | [README §Keys](../candy-code/README.md#keys) — global/palette/modal dispatch; slash commands in [COMMANDS.md](../candy-code/docs/COMMANDS.md); keys are rebindable per [SETTINGS.md](../candy-code/docs/SETTINGS.md), and the `App` claims some chords (e.g. Ctrl+K) before Chat sees them. Terminal/SSH questions: [TROUBLESHOOTING.md](../candy-code/docs/TROUBLESHOOTING.md). |
| **candy-top** | [README §Key bindings](../candy-top/README.md#key-bindings) — the same table feeds the in-app help overlay (`F1` / `?` / `h`) and `candy-top -h`; config options (including `vim_keys`, `disable_mouse`) in [§Config keys](../candy-top/README.md#config-keys). |
| **candy-files** | [README §Keys](../candy-files/README.md#keys) — including the honest [Ctrl+Tab caveat](../candy-files/README.md#terminal-support-for-ctrltab): most terminals send plain Tab for it; it works where modified-Tab reporting exists (Kitty-protocol terminals — and `Ctrl+PgUp`/`Ctrl+PgDn` as the universal alternative). tmux and browsers may intercept. |
| **candy-sql** | [README §Keys](../candy-sql/README.md#keys), plus [Admin Dashboard keys](../candy-sql/README.md#admin-dashboard-keys). |
| **candy-git** | [README §Keys](../candy-git/README.md#keys), plus [diff-viewer keys](../candy-git/README.md#diff-viewer-keys). |
| **candy-mdview** | [README §Pager keys](../candy-mdview/README.md#pager-keys). |
| **candy-tetris** | [README §Controls](../candy-tetris/README.md#controls) (+ [VS mode](../candy-tetris/README.md#vs-mode-controls)). |
| **candy-flappy** | [README §Keys](../candy-flappy/README.md#keys). |
| **candy-minesweeper** | [README §Keys](../candy-minesweeper/README.md#keys). |
| **candy-codetime** | [README §Keys (dashboard)](../candy-codetime/README.md#keys-dashboard). |
| **candy-gifview** | [README §Keys](../candy-gifview/README.md#keys). |
| **candy-image** | [README §Keys](../candy-image/README.md#keys-while---animate-plays) — only while `--animate` plays. |

## Making chords arrive at all

Whether `Ctrl+Tab`, `Ctrl+Enter`, or Alt+letter ever reach an app as
*themselves* is a terminal question, not an app question:

- **Kitty progressive keyboard protocol** (`CSI >1u` flags, disambiguate
  mode) — negotiated by apps like candy-code and candy-top at startup;
  support it or not, terminals either send the chord or send nothing new.
- **xterm `modifyOtherKeys`** — the older fallback for the same problem.
- Both (and the legacy encodings) are decoded in
  [sugar-input](../sugar-input/README.md); the byte-level state machine is
  [sugar-ansi-parser](../sugar-ansi-parser/README.md).
- Mouse: apps enable button+motion tracking (`?1002h`) with SGR encoding
  (`?1006h`); terminals without SGR mouse simply never answer — see
  candy-top's [mouse section](../candy-top/README.md#key-bindings).

Every app that draws a menu bar keeps keyboard equivalents for every mouse
affordance — if you find one that doesn't, that's a bug report, not a
workaround.

## Color and reduced-motion env

The env-var hub for the fleet is the shared table in the [sugar-core
README](../sugar-core/README.md#environment-variables); the color-depth ladder
(TERM/`COLORTERM`/`NO_COLOR`/force flags/CI) is documented by
[sugar-colorprofile](../sugar-colorprofile/README.md). `REDUCE_MOTION` /
`PREFERS_REDUCED_MOTION` behaviour and the honest per-app `NO_COLOR` status
are in [GETTING-STARTED.md §Accessibility & reduced
motion](GETTING-STARTED.md#accessibility--reduced-motion).

## Not yet here

Per-lib *layout maps* (ASCII diagrams of where each key is) don't exist yet
anywhere in the fleet; that gap is recorded in the audit evidence and would
be its own follow-up. This index will link them where they land.
