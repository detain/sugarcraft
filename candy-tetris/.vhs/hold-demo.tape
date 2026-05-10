# VHS tape — Tetris hold and lock delay demo
# Render with: `vhs candy-tetris/.vhs/hold-demo.tape`
Output .vhs/hold-demo.gif

Set FontSize 14
Set Width 720
Set Height 560
Set TypingSpeed 80ms
Set Theme "TokyoNight"

Type "./bin/tetris"
Enter
Sleep 1s

# Drop first piece
Space Sleep 800ms

# Use hold feature (c key) to swap current piece with held piece
c Sleep 600ms

# Hard drop the swapped piece
Space Sleep 800ms

# Drop another piece
Right Sleep 200ms
Space Sleep 800ms

# Hold again to swap
c Sleep 600ms
Space Sleep 800ms

# More gameplay
Left Sleep 200ms
Up Sleep 200ms
Space Sleep 800ms

# Linger to show final state
Sleep 2500ms
