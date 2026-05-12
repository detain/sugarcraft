# VHS tape — CandyKit Stage multi-section presentation.
# Render with: `vhs candy-kit/.vhs/stage.tape`
Output .vhs/stage.gif

Set FontSize 14
Set Width 700
Set Height 300
Set TypingSpeed 40ms
Set Theme "TokyoNight"

Type "php -r 'require \"vendor/autoload.php\"; use SugarCraft\\Kit\\Stage; use SugarCraft\\Kit\\Section; use SugarCraft\\Kit\\Theme; $t = Theme::ansi(); $s = Stage::new()->withSections([Section::new(\"Welcome\")->withContent(\"Welcome to CandyKit!\")->withTheme($t), Section::new(\"Features\")->withContent(\"Section, Stage, Banner, and more.\")->withTheme($t)]); echo $s->render();'"
Enter
Sleep 1s