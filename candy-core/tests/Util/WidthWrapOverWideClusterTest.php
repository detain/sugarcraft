<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;

/**
 * 15b-26: `Width::wrap()` hard-breaks an oversize word by repeatedly taking
 * `truncate($remaining, $max)`. A cluster wider than the whole budget (a CJK
 * glyph or a wide emoji at `$max = 1`) truncates to '', so the loop never
 * advanced and the process hung. The fix emits that cluster alone on an
 * over-wide row, the way `wrapAnsi()` already did.
 *
 * The regression is a hang, not a wrong answer, so each case runs under a
 * SIGALRM deadline that turns a non-terminating wrap into a test failure
 * instead of a stalled runner.
 */
final class WidthWrapOverWideClusterTest extends TestCase
{
    private const DEADLINE_SECONDS = 3;

    /** @return iterable<string, array{string, int, string}> */
    public static function overWideCases(): iterable
    {
        yield 'lone CJK at width 1' => ['文', 1, '文'];
        yield 'CJK between ASCII at width 1' => ['a文b', 1, "a\n文\nb"];
        yield 'emoji + skin tone at width 1' => ["👍\u{1F3FD}", 1, "👍\u{1F3FD}"];
        yield 'two CJK at width 1' => ['文字', 1, "文\n字"];
        yield 'CJK run at width 3 still packs' => ['ab文文cd', 3, "ab\n文\n文c\nd"];
    }

    #[DataProvider('overWideCases')]
    public function testWrapTerminatesAndKeepsEveryCluster(string $in, int $max, string $expected): void
    {
        $out = $this->withDeadline(static fn (): string => Width::wrap($in, $max));

        $this->assertSame($expected, $out);
        // Nothing dropped: the rows rejoin to the input.
        $this->assertSame($in, str_replace("\n", '', $out));
    }

    public function testWrapAnsiAgreesOnTheSameInput(): void
    {
        $this->assertSame(
            $this->withDeadline(static fn (): string => Width::wrapAnsi('a文b', 1)),
            $this->withDeadline(static fn (): string => Width::wrap('a文b', 1)),
        );
    }

    /**
     * @param callable(): string $fn
     */
    private function withDeadline(callable $fn): string
    {
        if (!\function_exists('pcntl_alarm')) {
            return $fn();
        }
        $prevAsync = pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function (): void {
            throw new \RuntimeException('Width::wrap() did not terminate within the deadline (15b-26)');
        });
        pcntl_alarm(self::DEADLINE_SECONDS);
        try {
            return $fn();
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, SIG_DFL);
            pcntl_async_signals($prevAsync);
        }
    }
}
