# Render with: cd sugar-overlay && vhs .vhs/basic.tape
#   The vhs.yml render job replays exactly this for this lib: real
#   charmbracelet/vhs with working-directory sugar-overlay, so the typed
#   command below runs in its PTY and examples/ resolves there.
Output .vhs/basic.gif

Set FontSize 14
Set Width 800
Set Height 540
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "php examples/basic.php"
Enter
Sleep 3s
