# VHS tape — dual-pane navigation. Uses the source tree itself as the
# left pane so there's content to browse.
# Render with: `vhs super-candy/.vhs/navigate.tape`
Output .vhs/navigate.gif

Set FontSize 14
Set Width 1100
Set Height 480
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "./bin/supercandy src tests"
Enter
Sleep 800ms

Down  Sleep 250ms
Down  Sleep 250ms
Down  Sleep 250ms
Enter Sleep 500ms       # cd into a subdir
Down  Sleep 250ms
Tab   Sleep 350ms       # swap to right pane
Down  Sleep 250ms
Down  Sleep 250ms
Tab   Sleep 350ms       # back to left
Type "h"  Sleep 400ms   # parent up
Type "s"  Sleep 350ms   # cycle sort
Type "s"  Sleep 350ms
Type "."  Sleep 400ms   # toggle hidden
Sleep 800ms
Type "q"
