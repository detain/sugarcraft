<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SugarCraft\CodeTime\Dashboard;
use SugarCraft\CodeTime\Heartbeat;
use SugarCraft\CodeTime\Renderer;
use SugarCraft\CodeTime\Stats;
use SugarCraft\CodeTime\Theme;
use SugarCraft\CodeTime\Tests\Support\NullReader;

/**
 * CT-S33 pins: the extracted palette holds EXACTLY the hexes the Renderer
 * shipped before the extraction, and the class obeys the repo immutability
 * law (private constructor, readonly properties). These values are the
 * byte-exactness contract every golden in the suite rests on — changing one
 * here must come with a disclosed golden move, never quietly.
 */
final class ThemeTest extends TestCase
{
    public function testTheDefaultPaletteIsTheShippedColours(): void
    {
        $theme = Theme::default();

        $this->assertSame('#ff5f87', $theme->title);
        $this->assertSame('#a78bfa', $theme->range);
        $this->assertSame('#6ee7b7', $theme->total);
        $this->assertSame('#fde68a', $theme->projects);
        $this->assertSame('#7dd3fc', $theme->ranking);
        $this->assertSame('#7d6e98', $theme->muted);
        $this->assertSame('#f87171', $theme->error);
        $this->assertSame('#fca5a5', $theme->note);
    }

    public function testTheThemeCannotBeBuiltOrMutatedFromTheOutside(): void
    {
        $class = new ReflectionClass(Theme::class);

        $this->assertTrue($class->isFinal());
        $constructor = $class->getConstructor();
        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate());
        foreach ($class->getProperties() as $property) {
            $this->assertTrue($property->isReadOnly(), $property->getName());
        }
    }

    public function testEachDefaultFactoryAnswersAFreshImmutableValue(): void
    {
        $this->assertNotSame(Theme::default(), Theme::default());
        $this->assertSame(Theme::default()->title, Theme::default()->title);
    }

    /**
     * The real CT-S33 extraction guard: under a truecolour profile every
     * default palette hex must reach the wire through the Renderer, so a
     * drifted slot (in Theme or at a call site) goes red HERE even when the
     * colour-stripped geometry goldens keep passing.
     */
    public function testTheRendererPaintsEveryDefaultPaletteSlotUnderTrueColor(): void
    {
        $end = new \DateTimeImmutable('2024-06-07');
        $beats = [
            new Heartbeat(
                time: strtotime('2024-06-05 10:00:00'),
                project: 'sugarcraft',
                language: 'php',
                file: '/x/a.php',
                duration: 3600,
            ),
        ];
        $stats = Stats::compute($beats, $end->modify('-6 days'), $end);
        $view = Renderer::render(new Dashboard(
            new NullReader(), $end, 7, $stats, 80,
            \SugarCraft\Core\Util\ColorProfile::TrueColor,
            null, 'sqlite', 1, 1, null, false, 3600,
        ));

        // ff5f87 title · a78bfa range · 6ee7b7 total+goal · fde68a projects
        // · 7dd3fc ranking · 7d6e98 muted chrome · fca5a5 notes · f87171 error
        foreach ([
            "\x1b[38;2;255;95;135m",
            "\x1b[38;2;167;139;250m",
            "\x1b[38;2;110;231;183m",
            "\x1b[38;2;253;230;138m",
            "\x1b[38;2;125;211;252m",
            "\x1b[38;2;125;110;152m",
            "\x1b[38;2;252;165;165m",
        ] as $sequence) {
            $this->assertStringContainsString($sequence, $view);
        }
    }

    /**
     * CT-S34: the door's vocabulary. Every name in NAMES resolves, unknown
     * names answer null (the CLI refuses them; the model never guesses), and
     * 'default' is exactly the shipped palette the goldens were born with.
     */
    public function testEveryNamedPaletteResolvesAndDefaultKeepsTheShippedBytes(): void
    {
        foreach (Theme::NAMES as $name) {
            $this->assertNotNull(Theme::named($name), $name);
        }

        $this->assertSame('#c2185b', Theme::named('light')?->title);
        $this->assertNull(Theme::named('poster'));
        $this->assertNull(Theme::named(''));
        $this->assertEquals(Theme::default(), Theme::named('default'));
    }
}
