# candy-freeze turns a code sample into an SVG "screenshot" — one per
# theme. The SVGs are image files, so a terminal recording can only show
# that they were written; open examples/out/*.svg (or the docs) to view
# the rendered, syntax-highlighted result. Earlier this tape `head`-ed a
# .svg, which just dumped raw XML — not a screenshot.
Output .vhs/screenshot.gif

Set FontSize 14
Set Width 900
Set Height 360
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "php examples/screenshot.php"
Enter
Sleep 1500ms
Type "ls -lh examples/out/*.svg"
Enter
Sleep 3s
