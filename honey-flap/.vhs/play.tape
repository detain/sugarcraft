# Short flap session — show the bird drop from the spawn, then a run of
# well-spaced flaps that keep it aloft and bobbing across the playfield
# before it tires out and lands. The model auto-ticks at ~30 fps, so the
# Sleeps between flaps set the rhythm. Flaps are ~1s apart: any tighter
# and the gentle gravity lets the bird climb into the ceiling.
Output .vhs/play.gif

Set FontSize 14
Set Width 720
Set Height 460
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "./bin/honey-flap"
Enter
Sleep 600ms

# Six paced flaps — the bird floats up-and-over instead of plummeting.
Space Sleep 1000ms
Space Sleep 1000ms
Space Sleep 1000ms
Space Sleep 1000ms
Space Sleep 1000ms
Space Sleep 1000ms

# Stop flapping — let it glide down and land for the game-over frame.
Sleep 2000ms
