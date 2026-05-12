# VHS tape — undo in dual-pane file manager.
# Delete a file then press u to undo the deletion.
# Uses a temp directory so undo is safe.
# Render with: `vhs super-candy/.vhs/undo.tape`
Output .vhs/undo.gif

Set FontSize 14
Set Width 1100
Set Height 480
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "./bin/supercandy /tmp/super-candy-test /tmp"
Enter
Sleep 800ms

Down  Sleep 200ms
Space Sleep 300ms     # select entry
Type "d"  Sleep 300ms # arm delete
Type "y"  Sleep 600ms # confirm delete
Sleep 400ms
Type "u"  Sleep 800ms # undo deletion
Type "q"
