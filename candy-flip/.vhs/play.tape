# Generate a tiny synthetic GIF on the fly via examples/play.php and
# play it back through candy-flip. Lets the tape stand on its own
# without bundling a binary GIF in the repo.
Output .vhs/play.gif

Set FontSize 13
Set Width 920
Set Height 540
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "php examples/play.php"
Enter
Sleep 1500ms

# Pause, step a couple of frames, toggle preset, resume.
Space Sleep 600ms
Right Sleep 400ms
Right Sleep 400ms
Type "d" Sleep 800ms
Right Sleep 400ms
Space Sleep 800ms

Sleep 2000ms
