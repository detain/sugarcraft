# VHS tape — multi-select in dual-pane file manager.
# Space selects, Ctrl+A selects all.
# Render with: `vhs super-candy/.vhs/multi-select.tape`
Output .vhs/multi-select.gif

Set FontSize 14
Set Width 1100
Set Height 480
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "bin/supercandy src tests"
Enter
Sleep 1s

Down  Sleep 200ms
Space Sleep 300ms  # select
Down  Sleep 200ms
Space Sleep 300ms  # select another
Ctrl+A Sleep 400ms # select all
Sleep 500ms

Type "q"