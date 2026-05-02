Output .vhs/log.gif
Set FontSize 14
Set Width 800
Set Height 320
Set TypingSpeed 50ms
Set Theme "TokyoNight"
Type "./bin/candyshell log --level info  'starting deploy'"
Enter
Sleep 400ms
Type "./bin/candyshell log --level debug 'fetching artifacts'"
Enter
Sleep 400ms
Type "./bin/candyshell log --level warn  'cache miss, rebuilding'"
Enter
Sleep 400ms
Type "./bin/candyshell log --level error 'unit tests failed'"
Enter
Sleep 1500ms
