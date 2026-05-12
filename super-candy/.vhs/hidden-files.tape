# VHS tape — hidden-file toggle in dual-pane file manager.
# Press . to reveal dotfiles, press again to hide them.
# Render with: `vhs super-candy/.vhs/hidden-files.tape`
Output .vhs/hidden-files.gif

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
Down  Sleep 200ms
Down  Sleep 200ms
Down  Sleep 200ms
Type "."  Sleep 600ms  # reveal hidden files
Sleep 600ms
Type "."  Sleep 600ms  # hide them again
Sleep 400ms

Type "q"
