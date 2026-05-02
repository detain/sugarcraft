# VHS tape — record a 5-second demo of the candy-core counter app.
# Render with: `vhs candy-core/.vhs/counter.tape`
# Output: candy-core/.vhs/counter.gif

Output .vhs/counter.gif

Set FontSize 16
Set Width 700
Set Height 220
Set TypingSpeed 60ms
Set PlaybackSpeed 1.0
Set Theme "Tokyo Night"

Type "php examples/counter.php"
Enter
Sleep 500ms

Up
Sleep 200ms
Up
Sleep 200ms
Up
Sleep 400ms
Up
Sleep 400ms
Up
Sleep 600ms
Down
Sleep 600ms
Down
Sleep 800ms
Type "q"
