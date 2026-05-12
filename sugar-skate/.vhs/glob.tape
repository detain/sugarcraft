# VHS tape — SugarSkate glob pattern matching on key listing.
# List keys with * wildcard pattern.
# Render with: `vhs sugar-skate/.vhs/glob.tape`
Output .vhs/glob.gif

Set FontSize 14
Set Width 700
Set Height 300
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "./bin/skate set user-john 'John Doe'"
Enter
Sleep 200ms
Type "./bin/skate set user-alice 'Alice Smith'"
Enter
Sleep 200ms
Type "./bin/skate set token-abc 'abc123'"
Enter
Sleep 200ms
Type "./bin/skate set token-xyz 'xyz789'"
Enter
Sleep 200ms
Type "./bin/skate list user-*"
Enter
Sleep 500ms
Type "./bin/skate list token-*"
Enter
Sleep 500ms
Type "./bin/skate delete user-john"
Enter
Sleep 200ms
Type "./bin/skate delete user-alice"
Enter
Sleep 200ms
Type "./bin/skate delete token-abc"
Enter
Sleep 200ms
Type "./bin/skate delete token-xyz"
Enter
Sleep 200ms
