# Run the screenshot example, then `cat` the dracula output.
Output .vhs/screenshot.gif

Set FontSize 14
Set Width 900
Set Height 360
Set Theme "Tokyo Night"

Type "php examples/screenshot.php"
Enter
Sleep 1s
Type "ls examples/out/"
Enter
Sleep 1.5s
Type "head -8 examples/out/dracula.svg"
Enter
Sleep 2.5s
