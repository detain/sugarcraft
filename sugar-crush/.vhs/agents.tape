# VHS tape — the full-pane agent dashboard.
#
# examples/agent-dashboard.php seeds an AgentManager and a
# BackgroundSupervisor with fixed data and opens on Pane::Agents, so the pane
# has rows to show without forking real workers (which would record different
# numbers on every take).
#
# ── Renderer, working directory, grid ──────────────────────────────────────
#
# .vhs/cli.tape's header carries the evidence; the short version is that CI
# renders this lib's tapes with the upstream charmbracelet/vhs binary from
# `sugar-crush/`. So the typed path is lib-relative, `Output` keeps its
# `.vhs/` prefix to land inside the artifact path the workflow uploads, and
# there is no `Set Shell` to add — vhs always spawns a shell, so this tape
# runs the app for real.
#
# 1200x380 at FontSize 14 is a 117x15 grid (measured with `stty size` under
# vhs v0.11.0). Shorter than the other tapes on purpose: driving the model
# directly shows this pane emitting a fixed 9 rows at every terminal height
# tried (12, 14, 15, 16, 31), so a taller frame buys nothing but blank rows under
# the box. The widest rendered row is exactly 117 columns in every beat below.
#
# ── What the beats actually record ─────────────────────────────────────────
#
# Checked by driving the model at 117x15 and diffing the frames:
#
#   * Down moves the selection, but the move is recorded as a COLOUR change
#     only — the slot label flips amber→gray. With the ANSI stripped the two
#     frames are byte-identical, so nothing in the layout announces it.
#   * Space opens the peek overlay on whatever is selected. After both Downs
#     that is slot 3, so the overlay is titled `suite-runner` and carries the
#     three seeded output lines. (Pressing Space first would have titled it
#     `reviewer`; this tape never does, so no retitling is on the GIF.)
#   * The tail in the overlay is the session's seeded `output` string, fixed
#     once at construction. It does not move while the recording runs.
#   * Escape closes the overlay and restores the plain list.
#
# ── Beat length is not load-bearing any more ───────────────────────────────
#
# The app is alive for 8.1s here (3 + 0.8 + 0.8 + 2.5 + 1 after the Enter),
# but that is a pacing choice, not a deadline. It used to be a deadline: both
# seeded sessions are still `isActive()`, so Chat installs a 2s background-poll
# tick and BackgroundSupervisor::tick() marked them Stalled once
# `time() - lastHeartbeat > 15`, relabelling BOTH rows to `waiting` at ~16s and
# migrating `docs-sync` out of the Completed group — a committed GIF that
# changed with nothing but elapsed wall time. examples/agent-dashboard.php now
# pins `lastHeartbeat` out of reach (its `$defuseHeartbeat` closure and the
# docblock above it explain why reflection, and why a demo may), so the statuses
# hold for as long as the process lives. Note `FixedAge` in that file is a
# separate fix and does not cover this: FixedAge pins the elapsed READOUTS, the
# defusal pins the status field.
Output .vhs/agents.gif

Set FontSize 14
Set Width 1200
Set Height 380
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Sleep 1s
Type "php examples/agent-dashboard.php"
Enter
Sleep 3s

# Move down the slot list — grouped Working / Completed, stable slot order.
Down
Sleep 800ms
Down
Sleep 800ms

# Peek at the selected worker's seeded output tail; Escape closes it.
Space
Sleep 2500ms
Escape
Sleep 1s

Ctrl+C
Sleep 1s
