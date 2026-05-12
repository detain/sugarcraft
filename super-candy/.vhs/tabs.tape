# VHS tape — tab management in dual-pane file manager.
# t duplicates tab, Tab swaps panes, Ctrl+w closes tab.
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
Tab Sleep 400ms  # swap panes
Tab Sleep 400ms  # swap back
Sleep 300ms
Ctrl+w Sleep 500ms    # close current tab
Sleep 400ms

Type "q"
