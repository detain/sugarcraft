# VHS tape — short Tetris play session: a few drops and rotations.
# Render with: `vhs candy-tetris/.vhs/play.tape`
Output .vhs/play.gif

Set FontSize 14
Set Width 720
Set Height 560
Set TypingSpeed 80ms
Set Theme "TokyoNight"

Type "./bin/tetris"
Enter
Sleep 1s

Left  Sleep 200ms
Left  Sleep 200ms
Up    Sleep 250ms  # rotate
Down  Sleep 200ms
Down  Sleep 200ms
Space Sleep 600ms  # hard drop

Right Sleep 200ms
Right Sleep 200ms
Up    Sleep 200ms
Space Sleep 600ms

Left  Sleep 200ms
Up    Sleep 200ms
Up    Sleep 200ms
Space Sleep 600ms

# Linger on a frame of active gameplay rather than exiting back to
# a bash prompt — looks better as the GIF's resting frame on the
# website's demo grid.
Sleep 2500ms
