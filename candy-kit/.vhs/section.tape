# VHS tape — CandyKit Section rendering.
# Render with: `vhs candy-kit/.vhs/section.tape`
Output .vhs/section.gif

Set FontSize 14
Set Width 700
Set Height 300
Set TypingSpeed 40ms
Set Theme "TokyoNight"

Type "php -r '
require \"vendor/autoload.php\";
use SugarCraft\\Kit\\Section;
use SugarCraft\\Kit\\Theme;

$theme = Theme::ansi();
$section = Section::new(\"Features\")
    ->withContent(\"CandyKit: Section, Stage, Banner, and more.\")
    ->withTheme($theme);
echo $section->render();
'"
Enter
Sleep 1s