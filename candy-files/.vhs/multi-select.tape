# VHS tape — multi-select in dual-pane file manager.
# Space toggles selection on each entry.
# Seeds its own /tmp fixture so the demo never depends on leftover state.
# Render with: `cd candy-files && vhs .vhs/multi-select.tape`
Output .vhs/multi-select.gif

Set FontSize 14
Set Width 1100
Set Height 480
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "mkdir -p /tmp/candy-files-demo && touch /tmp/candy-files-demo/alpha.txt /tmp/candy-files-demo/beta.txt /tmp/candy-files-demo/gamma.txt /tmp/candy-files-demo/delta.txt"
Enter
Sleep 400ms

Type "./bin/candy-files /tmp/candy-files-demo /tmp"
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
