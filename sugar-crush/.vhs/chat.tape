# VHS tape — the SugarCrush shell answering a question, offline.
#
# Points at examples/echo-chat.php rather than ./bin/sugarcrush on purpose:
# the binary reads ~/.sugar-crush/config.json and may reach a real provider,
# so its frames differ per machine and per API key. The example pins the
# offline EchoBackend and produces the same reply every time.
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
# vhs v0.11.0). `App` draws to whatever WindowSizeMsg reports, and the model
# was driven directly at 117x31 to check it: the tab bar, the files pane, the
# transcript and the status bar all fit, and the widest rendered row is
# exactly 117 columns — nothing overflows, so nothing wraps or scrolls. That
# check covers the opening frame and the typed-question frame; the reply frame
# is produced asynchronously and was not driven the same way.
Output .vhs/chat.gif

Set FontSize 14
Set Width 1200
Set Height 640
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Sleep 1s
Type "php examples/echo-chat.php"
Enter
Sleep 3s

Type "Explain the Elm architecture in one sentence"
Sleep 500ms
Enter
Sleep 3s

# Ctrl+C is the quit binding; q is an ordinary character in the input box.
Ctrl+C
Sleep 1s
