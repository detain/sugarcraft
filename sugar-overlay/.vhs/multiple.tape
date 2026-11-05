# Render with: cd sugar-overlay && vhs .vhs/multiple.tape
#   (CI renders from the lib dir; the typed command below resolves examples/ there)
Output .vhs/multiple.gif

Set FontSize 14
Set Width 800
Set Height 540
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "php examples/multiple-overlays.php"
Enter
Sleep 3s
