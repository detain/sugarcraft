# VHS tape — hidden-file toggle in dual-pane file manager.
# Press . to reveal dotfiles, press again to hide them.
# Seeds a fixture dir with real dotfiles — pointing at src/tests only ever
# worked when the checkout happened to carry dotfiles there (102).
# Render with: `cd candy-files && vhs .vhs/hidden-files.tape`
Output .vhs/hidden-files.gif

Set FontSize 14
Set Width 1100
Set Height 480
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "mkdir -p /tmp/candy-files-hidden && touch /tmp/candy-files-hidden/notes.txt /tmp/candy-files-hidden/todo.txt /tmp/candy-files-hidden/.draft /tmp/candy-files-hidden/.secrets"
Enter
Sleep 400ms

Type "./bin/candy-files /tmp/candy-files-hidden src"
Enter
Sleep 800ms

Down  Sleep 200ms
Down  Sleep 200ms
Down  Sleep 200ms
Down  Sleep 200ms
Down  Sleep 200ms
Type "."  Sleep 600ms  # reveal hidden files
Sleep 600ms
Type "."  Sleep 600ms  # hide them again
Sleep 400ms

Type "q"
