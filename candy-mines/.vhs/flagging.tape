# VHS tape — CandyMines flagging and difficulty modes.
# f toggles flag on cursor cell.
# Render with: `vhs candy-mines/.vhs/flagging.tape`
Output .vhs/flagging.gif

Set FontSize 14
Set Width 600
Set Height 400
Set TypingSpeed 0ms
Set Theme "TokyoNight"

Type "./bin/candy-mines"
Enter
Sleep 1s

Down  Sleep 200ms
Down  Sleep 200ms
Right Sleep 200ms
Type "f"  Sleep 300ms  # place flag
Right Sleep 200ms
Type "f"  Sleep 300ms  # place another flag
Right Sleep 200ms
Type "f"  Sleep 300ms  # toggle flag off (removes)
Sleep 500ms

Type "q"
