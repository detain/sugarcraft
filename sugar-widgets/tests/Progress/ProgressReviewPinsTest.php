<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Progress;

use PHPUnit\Framework\TestCase;
use SugarCraft\Widgets\Progress\AnimatedProgress;
use SugarCraft\Widgets\Progress\Progress;
use SugarCraft\Widgets\Progress\SpringTickMsg;

/**
 * Pins for the 2026-11 review findings on Progress/AnimatedProgress:
 * C2 (site sold Progress::new(ratio) while the factory took no argument —
 * the extra positional was silently ignored, rendering 0%),
 * m1 (NaN percent rendered the FULL bar), C3/M20 (bar runes accepted
 * forged zone sentinels), and m2 (the spring cache docblock promised
 * reuse while every copy() silently dropped it).
 */
final class ProgressReviewPinsTest extends TestCase
{
    public function testFactoryAcceptsTheDocumentedRatio(): void
    {
        $bar = Progress::new(0.42);
        self::assertSame(0.42, $bar->percent);
        self::assertStringContainsString('42%', $bar->view());
    }

    public function testNaNPercentRendersEmptyNotFull(): void
    {
        // The raw min/max ladder left NAN untouched by min(), and the fill
        // comparison then rounded NaN up to the full bar (m1).
        $bar = new Progress(NAN);
        self::assertSame(0.0, $bar->percent);
        $viaSetter = Progress::new()->withPercent(NAN);
        self::assertSame(0.0, $viaSetter->percent);
    }

    public function testForgedSentinelRuneIsStrippedAtIngress(): void
    {
        // U+E000/U+E001 are the candy-mouse zone sentinels; a rune forged
        // from them would splice fake zones into host frames (C3 family).
        $bar = Progress::new()->withRunes("\u{E000}x\u{E001}", "\u{009B}o");
        self::assertStringNotContainsString("\u{E000}", $bar->fullChar);
        self::assertStringNotContainsString("\u{E001}", $bar->fullChar);
        self::assertStringNotContainsString("\u{E000}", $bar->emptyChar);
        self::assertStringNotContainsString("\u{009B}", $bar->emptyChar);
        self::assertStringContainsString('x', $bar->fullChar);
        self::assertStringContainsString('o', $bar->emptyChar);
    }

    public function testSpringCacheSurvivesAnimationCopies(): void
    {
        $bar = AnimatedProgress::new(40)->withWidth(40);
        [$moving, $cmd] = $bar->setPercent(0.5);
        self::assertNotNull($cmd);

        $prop = new \ReflectionProperty(AnimatedProgress::class, 'spring');

        [$tick1, $cmd1] = $moving->update(new SpringTickMsg($moving->id, $moving->epoch));
        $spring1 = $prop->getValue($tick1);
        self::assertIsObject($spring1, 'first tick must have materialised the spring');

        [$tick2] = $tick1->update(new SpringTickMsg($tick1->id, $tick1->epoch));
        self::assertSame($spring1, $prop->getValue($tick2), 'm2: cache rides ordinary copies');

        // A tuning change must drop the cache so getSpring() re-tunes.
        $retuned = $tick2->withFps(30.0);
        self::assertNull($prop->getValue($retuned));
    }
}
