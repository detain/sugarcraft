# VHS tape — SugarStash git staging workflow.
# s key stages/unstages the highlighted file.
# Render with: `vhs sugar-stash/.vhs/stage.tape`
Output .vhs/stage.gif

Set FontSize 14
Set Width 1100
Set Height 400
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "cd super-candy && ../bin/sugar-stash"
Enter
Sleep 1s

Down  Sleep 300ms     # move cursor
Type "s" Sleep 400ms  # stage
Down  Sleep 300ms
Type "s" Sleep 400ms  # stage another
Type "s" Sleep 400ms  # unstage
Sleep 500ms
Type "R" Sleep 600ms  # refresh
Type "q"
