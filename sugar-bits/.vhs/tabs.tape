# Render with: vhs sugar-bits/.vhs/tabs.tape
Output .vhs/tabs.gif

Set FontSize 14
Set Width 720
Set Height 300
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "php examples/tabs.php"
Enter
Sleep 1s

# Navigate tabs
Tab
Sleep 400ms
Tab
Sleep 400ms
Tab
Sleep 400ms

# Jump to tab 1
Type "1"
Sleep 400ms

# Jump to tab 3
Type "3"
Sleep 400ms

Type "q"