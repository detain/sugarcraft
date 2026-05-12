# VHS tape — tab management in dual-pane file manager.
# t duplicates tab, Ctrl+w closes tab.
# Render with: `vhs super-candy/.vhs/tabs.tape`
Output .vhs/tabs.gif

Set FontSize 14
Set Width 1100
Set Height 480
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "super-candy/bin/supercandy src tests"
Enter
Sleep 1s

Down  Sleep 200ms
Down  Sleep 200ms
Type "t"  Sleep 500ms  # duplicate tab
Sleep 300ms
Type "h"  Sleep 400ms  # go up
Sleep 300ms
Ctrl+w Sleep 500ms    # close current tab
Sleep 400ms

Type "q"