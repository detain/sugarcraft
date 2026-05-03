# Short flap session — show the bird falling, a few flaps, scrolling
# pipes. The model auto-ticks at ~30 fps so we just need to wait.
Output .vhs/play.gif

Set FontSize 14
Set Width 720
Set Height 460
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "./bin/honey-flap"
Enter
Sleep 1500ms

# A handful of flaps spaced out so the bird goes up-down-up.
Space Sleep 350ms
Space Sleep 400ms
Space Sleep 600ms
Space Sleep 350ms
Space Sleep 500ms
Space Sleep 300ms
Space Sleep 600ms
Space Sleep 400ms
Space Sleep 350ms
Space Sleep 600ms

Sleep 2500ms
