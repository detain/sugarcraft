# VHS tape — the blocking permission prompt, and what refusing it leaves behind.
#
# examples/permission-prompt.php opens with the modal already up (a stock
# install has no configured hook that raises one, so waiting for a real
# trigger would record an empty screen). The tape answers `n`, which is the
# branch with an after-state worth showing: the refused call renders as a
# struck-through "⊘ denied" row in the transcript, with the reason left
# un-struck below it.
#
# The `n` beat was re-checked by driving the model at this tape's real grid
# (117x31): the modal's y / a / n-or-Esc rows give way to
# `🔧 tool: Bash ⊘ denied` and `Permission denied: Bash was not run.`, plus a
# `system:` line carrying the same reason, and the whole after-state fits the
# frame with room to spare.
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
# both with the modal up and after the refusal.
Output .vhs/permission.gif

Set FontSize 14
Set Width 1200
Set Height 640
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Sleep 1s
Type "php examples/permission-prompt.php"
Enter
Sleep 3s

# Refuse. Escape does the same thing; `y` allows once and `a` for the session.
Type "n"
Sleep 3s

Ctrl+C
Sleep 1s
