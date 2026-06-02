# VHS tape — multi-select in dual-pane file manager.
# Space toggles selection on each entry.
# Uses a temp directory so delete is safe.
# Render with: `vhs candy-files/.vhs/multi-select.tape`
Output .vhs/multi-select.gif

Set FontSize 14
Set Width 1100
Set Height 480
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "./bin/candyfiles /tmp/candy-files-test /tmp"
Enter
Sleep 800ms

Down  Sleep 200ms     # move to second entry
Space Sleep 300ms     # select first
Down  Sleep 200ms     # move down
Space Sleep 300ms     # select second
Down  Sleep 200ms     # move down
Space Sleep 300ms     # select third
Sleep 400ms

Type "q"
