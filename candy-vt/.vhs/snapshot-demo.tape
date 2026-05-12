# VHS tape — feed-and-screenshot demo
#
# Render with:
#   vhs candy-vt/.vhs/snapshot-demo.tape
#
# CI re-renders on every push that touches candy-vt/src or this tape.

Output .vhs/snapshot-demo.gif

Set Theme "TokyoNight"
Set FontSize 14
Set Width 800
Set Height 600
Set Padding 16
Set TypingSpeed 50ms

Type "php examples/feed-and-screenshot.php"
Enter
Sleep 2s