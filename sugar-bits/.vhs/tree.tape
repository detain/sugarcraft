# VHS tape — interactive Tree with expand/collapse navigation.
# Enter toggles expand, h/l collapse/expand.
# Render with: `vhs sugar-bits/.vhs/tree.tape`
Output .vhs/tree.gif

Set FontSize 16
Set Width 700
Set Height 300
Set TypingSpeed 40ms
Set Theme "TokyoNight"

Type "php examples/tree.php"
Enter
Sleep 1s

Down  Sleep 300ms     # move to Projects
Enter Sleep 400ms     # expand Projects
Down  Sleep 300ms     # move to app.php
Enter Sleep 300ms     # toggle (collapse Projects)
Right Sleep 300ms     # expand current branch
Down  Sleep 300ms     # move to README.md
Left  Sleep 300ms     # collapse
Sleep 500ms

Type "q"
