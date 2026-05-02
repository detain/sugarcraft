# VHS tape — chat shell with the offline EchoBackend (the binary's
# default when SUGARCRUSH_BACKEND_CMD isn't set).
# Render with: `vhs sugar-crush/.vhs/chat.tape`
Output .vhs/chat.gif

Set FontSize 14
Set Width 900
Set Height 460
Set TypingSpeed 50ms
Set Theme "Tokyo Night"

Type "./bin/sugarcrush"
Enter
Sleep 800ms

Type "explain fiber-based scheduling in PHP"
Enter
Sleep 1s

Type "what does the cursed renderer save?"
Enter
Sleep 1s

Escape
