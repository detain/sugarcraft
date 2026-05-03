# Drive candy-query against a tmp sqlite seeded by examples/play.php
# so the recording reproduces without a global DSN.
Output .vhs/play.gif

Set FontSize 13
Set Width 1000
Set Height 600
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "php examples/play.php"
Enter
Sleep 1500ms

# Cycle through tables.
Down  Sleep 400ms
Down  Sleep 400ms
Enter Sleep 800ms

# Open query pane, run a quick query.
Tab   Sleep 400ms
Tab   Sleep 400ms
Type "SELECT * FROM users WHERE id > 1" Sleep 600ms
Ctrl+r Sleep 1500ms

Sleep 1500ms
