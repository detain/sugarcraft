# VHS tape — CandyFreeze rendering ANSI-colored code input.
# Shows SGR codes being parsed into colored SVG spans.
# Render with: `vhs candy-freeze/.vhs/ansi-input.tape`
Output .vhs/ansi-input.gif

Set FontSize 14
Set Width 700
Set Height 300
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "printf '\x1b[1;31merror:\x1b[0m \x1b[33mwarning:\x1b[0m \x1b[32msuccess\x1b[0m\n' | ./bin/candyfreeze --theme dracula"
Enter
Sleep 1s
Type "echo 'fn main() {}' | ./bin/candyfreeze --theme tokyo-night --line-numbers"
Enter
Sleep 1s
