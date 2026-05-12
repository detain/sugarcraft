# VHS tape — CandyKit Section rendering.
# Render with: `vhs candy-kit/.vhs/section.tape`
Output .vhs/section.gif

Set FontSize 14
Set Width 700
Set Height 300
Set TypingSpeed 40ms
Set Theme "TokyoNight"

Type "php -r 'require \"vendor/autoload.php\"; use SugarCraft\\Kit\\Section; use SugarCraft\\Kit\\Theme; $s = Section::new(\"Features\")->withContent(\"Section, Stage, Banner, and more.\")->withTheme(Theme::ansi()); echo $s->render();'"
Enter
Sleep 1s