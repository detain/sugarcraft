# VHS tape — tab management in dual-pane file manager.
# t duplicates tab, Ctrl+Tab cycles, Ctrl+w closes tab.
# Render with: `vhs super-candy/.vhs/tabs.tape`
Output .vhs/tabs.gif

Set FontSize 14
Set Width 1100
Set Height 480
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "./bin/supercandy src tests"
Enter
Sleep 800ms

Down  Sleep 200ms
Down  Sleep 200ms
Type "t"  Sleep 500ms  # duplicate tab
Sleep 300ms
Ctrl+Tab Sleep 400ms  # cycle to next tab
Ctrl+Tab Sleep 400ms  # cycle again
Ctrl+Shift+Tab Sleep 400ms  # cycle back
Sleep 300ms
Ctrl+w Sleep 500ms    # close current tab
Sleep 400ms

Type "q"
