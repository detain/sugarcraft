# VHS tape — hidden files toggle in dual-pane file manager.
# Dot (.) toggles hidden files display.
# Render with: `vhs super-candy/.vhs/hidden-files.tape`
Output .vhs/hidden-files.gif

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
Type "."  Sleep 400ms   # toggle hidden files
Sleep 600ms
Type "."  Sleep 400ms   # toggle back
Sleep 500ms

Type "q"