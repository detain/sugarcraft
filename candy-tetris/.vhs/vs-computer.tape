# VHS tape — Tetris VS Computer mode demo
# Render with: `vhs candy-tetris/.vhs/vs-computer.tape`
Output .vhs/vs-computer.gif

Set FontSize 14
Set Width 900
Set Height 560
Set TypingSpeed 80ms
Set Theme "TokyoNight"

Type "./bin/tetris -v"
Enter
Sleep 1s

# Let the computer play for a few seconds
Sleep 5s

# Exit VS mode
Type "q"
Sleep 500ms
