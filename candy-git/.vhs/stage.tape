# VHS tape — CandyGit git staging workflow.
# s key stages/unstages the highlighted file.
# Drives the hermetic staging fixture (php examples/stage.php): the s presses
# visibly change staged/unstaged letters on a deterministic row set, so the
# render no longer depends on the checked-out repository's git state.
# Render with: `vhs candy-git/.vhs/stage.tape`
Output .vhs/stage.gif

Set FontSize 14
Set Width 1100
Set Height 400
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "php examples/stage.php"
Enter
Sleep 1500ms

Type "s" Sleep 400ms  # stage src/Renderer.php (M -> staged)
Down  Sleep 300ms
Type "s" Sleep 400ms  # stage src/DiffViewer.php
Type "s" Sleep 400ms  # unstage it again
Sleep 500ms
Type "R" Sleep 600ms  # refresh
Type "q"
Sleep 200ms
