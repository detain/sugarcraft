# VHS tape — search mode in dual-pane file manager.
# Type "/" to enter search, type query, navigate results, open.
# Render with: `vhs super-candy/.vhs/search.tape`
Output .vhs/search.gif

Set FontSize 14
Set Width 1100
Set Height 480
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "./bin/supercandy src tests"
Enter
Sleep 800ms

Type "/"              # enter search mode
Sleep 300ms
Type "Manager"        # type partial filename
Sleep 500ms
Down  Sleep 250ms     # navigate results
Down  Sleep 250ms
Enter Sleep 500ms     # open directory result or exit search on file
Sleep 800ms

Type "q"
