# VHS tape — CandyKit Logo rendering with color options.
# Shows Logo::sugarcraft() preset and custom color application.
# Render with: `vhs candy-kit/.vhs/logo.tape`
Output .vhs/logo.gif

Set FontSize 14
Set Width 700
Set Height 300
Set TypingSpeed 40ms
Set Theme "TokyoNight"

Type "php -r '
require \"vendor/autoload.php\";
use SugarCraft\Kit\Logo;

echo Logo::sugarcraft()->withColor(\"#ff6bac\")->render();
echo \"\n\n\";
echo Logo::fromAscii(\"HI\")->withColor(\"#50fa7b\")->render();
'"
Enter
Sleep 2s
