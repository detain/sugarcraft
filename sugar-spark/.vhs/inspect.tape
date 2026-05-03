# Pipe a few interesting escape sequences through sugarspark and
# show how they get labelled. Height bumped so the whole tour fits
# on one screen rather than scrolling the early SGR / DEC sections
# off the top.
Output .vhs/inspect.gif

Set FontSize 13
Set Width 900
Set Height 620
Set Theme "TokyoNight"

Type "./examples/inspect.sh"
Enter
Sleep 5s
