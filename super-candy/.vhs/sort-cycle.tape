# VHS tape — sort cycling in dual-pane file manager.
# Press s repeatedly to cycle through all 6 sort modes.
# Render with: `vhs super-candy/.vhs/sort-cycle.tape`
Output .vhs/sort-cycle.gif

Set FontSize 14
Set Width 1100
Set Height 480
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "./bin/supercandy src tests"
Enter
Sleep 800ms

Type "s"  Sleep 400ms  # name-asc → name-desc
Type "s"  Sleep 400ms  # name-desc → mtime-asc
Type "s"  Sleep 400ms  # mtime-asc → mtime-desc
Type "s"  Sleep 400ms  # mtime-desc → size-asc
Type "s"  Sleep 400ms  # size-asc → size-desc
Type "s"  Sleep 400ms  # size-desc → name-asc (cycle complete)
Sleep 800ms

Type "q"
