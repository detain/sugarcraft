# VHS tape — short minesweeper session: scan the cursor, reveal a few
# cells, plant a flag, and rest on a frame of active gameplay.
Output .vhs/play.gif

Set FontSize 14
Set Width 700
Set Height 460
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "./bin/candy-mines 10 8 10"
Enter
Sleep 1500ms

# Walk the cursor a bit so the highlight is visible.
Right Sleep 200ms
Right Sleep 200ms
Down  Sleep 200ms
Down  Sleep 200ms
Right Sleep 200ms

# First reveal — always safe, triggers flood fill.
Space Sleep 1200ms

# Move + flag.
Up    Sleep 200ms
Up    Sleep 200ms
Right Sleep 200ms
Type "f" Sleep 600ms

# Move + reveal a couple more.
Right Sleep 200ms
Down  Sleep 200ms
Space Sleep 600ms
Down  Sleep 200ms
Right Sleep 200ms
Space Sleep 800ms

Sleep 2500ms
