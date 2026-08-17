# VHS tape — how an Edit result renders: a gutter-numbered unified diff.
#
# examples/edit-diff.php seeds one Edit ToolResult carrying a real unified
# diff on ToolResult::$diff, which is the field Renderer::renderDiff() paints
# from — old/new line numbers in the gutter, +/- colouring, one logical line
# per terminal row. Its `+` line is wider than the diff box on purpose: it must
# CLIP, not wrap, and driving the model at this tape's grid confirms it does
# (the row ends `... self::defaultEnv()) ?? s`, cut mid-token, with the next
# row still the next diff line).
#
# Ctrl+O expands the collapsed tool body above the diff, so the tape shows
# both the collapsed and expanded states. That beat used to be a no-op: the
# seeded hunk was tall enough to fill the chat viewport by itself, which pushed
# the `… 1 line hidden (ctrl+o)` indicator off the top of the frame, and the
# frames either side of the keystroke came out byte-identical — three seconds
# of a still picture. The hunk is now short enough that the whole tool block
# fits, and driving the model at 117x31 flips one visible row from
# `… 1 line hidden (ctrl+o)` to `Applied 1 hunk to src/Tui/TerminalBackground.php`.
# Re-checked at 117x27, 117x29 and 117x35 too, so the beat does not hinge on
# one exact height.
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
# 1200x640 at FontSize 14 is a 117x31 grid (measured with `stty size` under
# vhs v0.11.0). The widest rendered row at that grid is exactly 117 columns,
# both before and after the toggle.
Output .vhs/diff.gif

Set FontSize 14
Set Width 1200
Set Height 640
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Sleep 1s
Type "php examples/edit-diff.php"
Enter
Sleep 3s

# Expand the collapsed tool output above the diff.
Ctrl+O
Sleep 3s

Ctrl+C
Sleep 1s
