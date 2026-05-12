# VHS tape — CandyKit multi-section Stage presentation.
# Render with: `vhs candy-kit/.vhs/stage.tape`
Output .vhs/stage.gif

Set FontSize 14
Set Width 700
Set Height 350
Set TypingSpeed 40ms
Set Theme "TokyoNight"

Type "php -r '
require \"vendor/autoload.php\";
use SugarCraft\Kit\{Stage, Section, Theme};

$theme = Theme::ansi();
$stage = Stage::new();
$stage = $stage->withSections([
    Section::new(\"Slide 1: Welcome\")
        ->withContent(\"Welcome to the presentation!\")
        ->withTheme($theme),
    Section::new(\"Slide 2: Features\")
        ->withContent(\"CandyKit ships with Section, Stage, Banner, and more.\")
        ->withTheme($theme),
]);
echo $stage->render();
'"
Enter
Sleep 2s
