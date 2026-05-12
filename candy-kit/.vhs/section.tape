# VHS tape — CandyKit Section component for styled output.
# Render with: `vhs candy-kit/.vhs/section.tape`
Output .vhs/section.gif

Set FontSize 14
Set Width 700
Set Height 350
Set TypingSpeed 40ms
Set Theme "TokyoNight"

Type "php -r '
require \"vendor/autoload.php\";
use SugarCraft\Kit\{Section, Theme};

$theme = Theme::ansi();
echo Section::new(\"Getting Started\")
    ->withContent(\"Welcome to CandyKit! This section demonstrates the Section component.\")
    ->withTheme($theme)
    ->render();
echo \"\n\";
echo Section::new(\"Features\")
    ->withContent(\"- StatusLine helpers\n- Banner titles\n- Section grouping\n- Themeable styles\")
    ->withTheme($theme)
    ->render();
'"
Enter
Sleep 2s
