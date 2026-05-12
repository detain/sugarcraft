# VHS tape — Tetris hold and hard drop demo
# Render with: `vhs candy-tetris/.vhs/hold-demo.tape`
Output .vhs/hold-demo.gif

Set FontSize 14
Set Width 720
Set Height 560
Set TypingSpeed 80ms
Set Theme "TokyoNight"

Type "bin/tetris"
Enter
Sleep 1s

# Hard drop a piece
Space Sleep 800ms

# Use hold (c key)
c Sleep 400ms

# Hard drop
Space Sleep 800ms

# Drop another piece
Right Sleep 200ms
Space Sleep 800ms

Sleep 2000ms