# VHS tape — query history cycling and favoriting in CandyQuery.
# Up/Down cycles through query history, Ctrl+F favorites.
# Render with: `vhs candy-query/.vhs/query-history.tape`
Output .vhs/query-history.gif

Set FontSize 14
Set Width 1000
Set Height 450
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "candy-query/bin/candy-query /tmp/candy-query-demo/sample.db"
Enter
Sleep 1s

Type "SELECT * FROM users;" Sleep 300ms
Enter Sleep 500ms  # run query
Up   Sleep 300ms     # cycle history
Up   Sleep 300ms
Down Sleep 300ms
Ctrl+F Sleep 400ms  # favorite
Sleep 500ms
Type "q"
Sleep 200ms