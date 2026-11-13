# VHS tape — undo in dual-pane file manager.
# Delete a file then press u to undo the deletion.
# Seeds its own /tmp fixture so the demo never depends on leftover state:
# without provisioning the launch fail-fasts and the GIF renders the error.
# Render with: `cd candy-files && vhs .vhs/undo.tape`
Output .vhs/undo.gif

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

Down  Sleep 200ms
Space Sleep 300ms     # select entry
Type "d"  Sleep 300ms # arm delete
Type "y"  Sleep 600ms # confirm delete
Sleep 400ms
Type "u"  Sleep 800ms # undo deletion
Type "q"
