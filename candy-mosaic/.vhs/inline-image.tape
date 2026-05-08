# VHS tape for candy-mosaic/examples/inline-image.php
# Renders a gradient fixture using the best available protocol.
# VHS emulates an iTerm2-compatible terminal, so Mosaic::auto()
# will select the iTerm2 renderer (OSC 1337 inline image).
Output .vhs/inline-image.gif

Set FontSize 14
Set Width 900
Set Height 600
Set Theme "Dracula"

Type "php examples/inline-image.php examples/fixture-gradient.png 50"
Enter
Sleep 2s
