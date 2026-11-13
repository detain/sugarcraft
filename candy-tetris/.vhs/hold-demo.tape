# VHS tape — Tetris hold-slot demo
# Render with (from the candy-tetris directory): vhs .vhs/hold-demo.tape
Output .vhs/hold-demo.gif

Set FontSize 14
Set Width 720
Set Height 560
Set TypingSpeed 80ms
Set Theme "TokyoNight"

Type "./bin/candy-tetris"
Enter
Sleep 2s

# The first-run card (candy-tetris-038) owns the opening press — 'begin'
# answers Space and Enter — and only then does the demo body drive the game.
Space
Sleep 1200ms

Space Sleep 800ms
Type "c"
Sleep 800ms
Space Sleep 800ms
Type "c"
Sleep 800ms
Space Sleep 800ms

Sleep 2000ms
# Leaving is a two-step dance in live play (candy-tetris-054): the first
# q arms the confirm, the second one leaves — one Type, two keydowns.
Type "qq"
Sleep 500ms
