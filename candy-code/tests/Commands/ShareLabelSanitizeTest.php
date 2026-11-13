<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Commands\ShareCommand;
use SugarCraft\Core\Util\Width;

/**
 * Pins the share-list label policy (canonical Sanitize + Width, no
 * hand-rolled strip): escapes/controls gone, and the cap lands on a
 * grapheme boundary so a combining cluster is never split.
 */
final class ShareLabelSanitizeTest extends TestCase
{
    private function label(string $in): string
    {
        $m = new \ReflectionMethod(ShareCommand::class, 'printableLabel');

        return (string) $m->invoke(new ShareCommand(), $in);
    }

    public function testEscapesAndControlsAreStripped(): void
    {
        self::assertSame('a b', $this->label("\e[31ma\nb\x07\x7f"));
    }

    public function testCombiningClusterAtTheCapIsNotSplit(): void
    {
        $cluster = "e\u{0301}";
        $out = $this->label(str_repeat('x', 59) . $cluster . 'tail');

        self::assertSame(str_repeat('x', 59) . $cluster, $out);
        self::assertSame(60, Width::of($out));
    }

    public function testWideLabelIsCappedInCells(): void
    {
        self::assertLessThanOrEqual(60, Width::of($this->label(str_repeat('漢', 80))));
    }
}
