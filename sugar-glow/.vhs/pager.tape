# VHS tape — SugarGlow markdown pager mode.
# -p flag opens fullscreen pager with scroll.
# Render with: `vhs sugar-glow/.vhs/pager.tape`
Output .vhs/pager.gif

Set FontSize 14
Set Width 900
Set Height 500
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "echo '# Hello\n\nThis is a **test** of the markdown pager.\n\n- item one\n- item two\n- item three\n\n## Section Two\n\nMore content here with `code` and *italic*.' | ./bin/sugarglow -p"
Enter
Sleep 1s

Down  Sleep 300ms
Down  Sleep 300ms
Down  Sleep 300ms
Down  Sleep 300ms
Sleep 500ms
Type "q"
