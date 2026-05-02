# VHS tape — wishlist picker. Uses the bundled sample config so the
# tape is reproducible without network access; we override the ssh
# binary to /bin/true so Enter doesn't actually try to connect.
# Render with: `vhs sugar-wishlist/.vhs/picker.tape`
Output .vhs/picker.gif

Set FontSize 14
Set Width 800
Set Height 360
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "./bin/wishlist --config examples/sample-wishlist.yml --ssh /bin/true"
Enter
Sleep 800ms

Down Sleep 300ms
Down Sleep 300ms
Up   Sleep 350ms
Type "stag"  Sleep 600ms       # filter narrows to staging
Backspace    Sleep 250ms
Backspace    Sleep 250ms
Backspace    Sleep 250ms
Backspace    Sleep 350ms       # back to full list
Down Sleep 300ms
Down Sleep 300ms
Sleep 800ms
Escape
