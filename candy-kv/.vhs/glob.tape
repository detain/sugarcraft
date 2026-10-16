# VHS tape — CandyKv glob pattern matching on key listing.
# List keys with * wildcard pattern.
# Render with: `vhs candy-kv/.vhs/glob.tape`
Output .vhs/glob.gif

Set FontSize 14
Set Width 700
Set Height 300
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "./bin/candy-kv set user-john 'John Doe'"
Enter
Sleep 200ms
Type "./bin/candy-kv set user-alice 'Alice Smith'"
Enter
Sleep 200ms
Type "./bin/candy-kv set token-abc 'abc123'"
Enter
Sleep 200ms
Type "./bin/candy-kv set token-xyz 'xyz789'"
Enter
Sleep 200ms
Type "./bin/candy-kv list user-*"
Enter
Sleep 500ms
Type "./bin/candy-kv list token-*"
Enter
Sleep 500ms
Type "./bin/candy-kv delete user-john"
Enter
Sleep 200ms
Type "./bin/candy-kv delete user-alice"
Enter
Sleep 200ms
Type "./bin/candy-kv delete token-abc"
Enter
Sleep 200ms
Type "./bin/candy-kv delete token-xyz"
Enter
Sleep 200ms
