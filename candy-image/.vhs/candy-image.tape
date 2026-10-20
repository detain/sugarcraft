# Build a synthetic PNG on the fly via examples/candy-image.php and render it
# through the candy-image CLI itself. Lets the tape stand on its own without
# bundling a binary image in the repo.
Output .vhs/candy-image.gif

Set FontSize 13
Set Width 920
Set Height 540
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "php examples/candy-image.php"
Enter
Sleep 1200ms

Type "php bin/candy-image --dump-detect"
Enter
Sleep 2500ms
