# Render the project README via the local bin (no global composer
# install required at render time). VHS runs from the lib's own
# working dir, so `./bin/candy-mdview` and `../README.md` both resolve.
Output .vhs/render.gif

Set FontSize 14
Set Width 900
Set Height 600
Set Theme "TokyoNight"

Type "./bin/candy-mdview --theme dark ../README.md"
Enter
Sleep 4s
