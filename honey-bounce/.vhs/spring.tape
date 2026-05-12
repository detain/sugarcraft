# VHS tape — HoneyBounce spring physics demonstration.
# Shows damped spring oscillation with configurable tension/friction.
# Render with: `vhs honey-bounce/.vhs/spring.tape`
Output .vhs/spring.gif

Set FontSize 14
Set Width 700
Set Height 300
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "php -r '
require \"vendor/autoload.php\";
use SugarCraft\Bounce\{Spring, Simulator};

$spring = new Spring(200.0, 12.0, 1.0);
$sim = new Simulator($spring, 0.05);

echo \"Spring oscillation (tension=200, friction=12):\n\n\";
$positions = [];
for ($i = 0; $i < 40; $i++) {
    $sim->step();
    $positions[] = $sim->position();
}

$min = min($positions);
$max = max($positions);
$range = $max - $min ?: 1;
$height = 10;
foreach (array_reverse(range(0, $height - 1)) as $row) {
    $line = \"\";
    foreach ($positions as $p) {
        $normalized = (int)(($p - $min) / $range * ($height - 1));
        $line .= $normalized === $row ? \"*\" : \" \";
    }
    echo \"$line\n\";
}
'"
Enter
Sleep 2s
