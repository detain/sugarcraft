# Drive sugar-stash against the example fixture-backed Model so the
# tape doesn't depend on a populated git repo at render time.
Output .vhs/play.gif

Set FontSize 13
Set Width 900
Set Height 540
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "php examples/play.php"
Enter
Sleep 1500ms

# Move cursor in the status pane.
Down  Sleep 400ms
Down  Sleep 400ms

# Cycle to branches.
Tab   Sleep 600ms
Down  Sleep 400ms

# Cycle to log.
Tab   Sleep 600ms
Down  Sleep 400ms
Down  Sleep 400ms

# Back to status; toggle a stage.
Tab   Sleep 600ms
Up    Sleep 300ms
Type "s" Sleep 700ms

Sleep 2000ms
