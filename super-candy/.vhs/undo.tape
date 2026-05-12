# VHS tape — undo operation in dual-pane file manager.
# Ctrl+Z undoes the last operation.
# Render with: `vhs super-candy/.vhs/undo.tape`
Output .vhs/undo.gif

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
Type "s"  Sleep 300ms  # stage a file
Ctrl+Z Sleep 400ms    # undo
Sleep 500ms

Type "q"