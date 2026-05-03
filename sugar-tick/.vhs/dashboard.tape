# Seed a fixture data dir, then drive sugar-tick against it. Lets the
# tape stand on its own with no global config / no editor plug-in.
Output .vhs/dashboard.gif

Set FontSize 13
Set Width 900
Set Height 540
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "php examples/dashboard.php"
Enter
Sleep 1500ms

Left  Sleep 700ms
Left  Sleep 700ms
Right Sleep 700ms
Type "r" Sleep 800ms

Sleep 2500ms
