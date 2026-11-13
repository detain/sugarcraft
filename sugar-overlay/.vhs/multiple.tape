# Render with: cd sugar-overlay && vhs .vhs/multiple.tape
#   The vhs.yml render job replays exactly this for this lib: real
#   charmbracelet/vhs with working-directory sugar-overlay, so the typed
#   command below runs in its PTY and examples/ resolves there.
Output .vhs/multiple.gif

Set FontSize 14
Set Width 800
Set Height 540
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "php examples/multiple-overlays.php"
Enter
Sleep 3s
