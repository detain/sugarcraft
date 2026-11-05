# VHS tape — interactive Tabs panel.
# Tab navigates, 1-9 jumps to a tab, q quits.
# Render with: `vhs sugar-widgets/.vhs/tabs.tape`
Output .vhs/tabs.gif

Set FontSize 16
Set Width 700
Set Height 260
Set Theme "TokyoNight"

Type "php examples/tabs.php"
Enter
Sleep 1s

Tab   Sleep 400ms     # Home → Profile
Tab   Sleep 400ms     # Profile → Settings
Type "1" Sleep 400ms  # jump to Home
Type "4" Sleep 400ms  # jump to About
Sleep 500ms

Type "q"
