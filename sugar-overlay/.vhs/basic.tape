# Render with: cd sugar-overlay && vhs .vhs/basic.tape
#   (CI renders from the lib dir; the typed command below resolves examples/ there)
Output .vhs/basic.gif

Set FontSize 14
Set Width 800
Set Height 540
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "php examples/basic.php"
Enter
Sleep 3s
