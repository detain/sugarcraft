# VHS tape — sort mode cycling in dual-pane file manager.
# s cycles through sort modes (name/size/time).
# Render with: `vhs super-candy/.vhs/sort-cycle.tape`
Output .vhs/sort-cycle.gif

Set FontSize 14
Set Width 1100
Set Height 480
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "bin/supercandy src tests"
Enter
Sleep 1s

Down  Sleep 200ms
Down  Sleep 200ms
Type "s"  Sleep 400ms  # cycle sort mode
Type "s"  Sleep 400ms  # cycle again
Type "s"  Sleep 400ms  # cycle back
Sleep 500ms

Type "q"