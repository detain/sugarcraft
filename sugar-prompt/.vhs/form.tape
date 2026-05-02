# Run through the multi-page form, picking sample answers.
Output .vhs/form.gif

Set FontSize 16
Set Width 800
Set Height 480
Set Theme "Tokyo Night"

Type "php examples/multi-page-form.php"
Enter
Sleep 1s

# Page 1 — name input
Tab                           # past the Note
Sleep 400ms
Type "Joe"
Sleep 600ms
Tab                           # to Confirm
Sleep 400ms
Type "y"
Sleep 600ms

# Page 2 — frequency Select
Sleep 800ms
Down
Sleep 400ms
Down
Sleep 400ms
Up
Sleep 400ms
Enter
Sleep 800ms

# Page 3 — submit
Enter
Sleep 1.5s
