# candy-freeze can capture ANSI terminal output: pipe SGR-colored text
# in and it parses the color codes into a styled SVG screenshot. We first
# print the colored line so the recording actually shows the colors, then
# freeze it to an SVG. (The SVG is an image — we confirm the write rather
# than dump its XML, which is all the old tape showed.)
Output .vhs/ansi-input.gif

Set FontSize 14
Set Width 760
Set Height 320
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "printf '\x1b[1;31merror:\x1b[0m \x1b[33mwarning:\x1b[0m \x1b[32msuccess\x1b[0m\n'"
Enter
Sleep 1s
Type "printf '\x1b[1;31merror:\x1b[0m \x1b[33mwarning:\x1b[0m \x1b[32msuccess\x1b[0m\n' | ./bin/candyfreeze --theme dracula -o /tmp/ansi.svg"
Enter
Sleep 800ms
Type "ls -lh /tmp/ansi.svg"
Enter
Sleep 2500ms
