<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Memory;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Memory\UnreadableNotes;

/**
 * The user-facing wording for skipped memory notes — the launch row and the
 * `/memory` section — pinned on its own, so the two consumers
 * (Bootstrap::reportMemorySkips() and Chat's /memory answers) share one tested
 * rendering.
 */
final class UnreadableNotesTest extends TestCase
{
    public function testNothingUnreadableSaysNothing(): void
    {
        self::assertSame('', UnreadableNotes::notice([]));
        self::assertSame([], UnreadableNotes::rows([]));
    }

    public function testTheNoticeAgreesInNumberAndBreaksTheCountDownByScope(): void
    {
        self::assertSame(
            '1 memory note could not be read and was skipped (user: 1); it is not in the prompt'
                . ' — `/memory list <scope>` names each file and why',
            UnreadableNotes::notice(['/h/.sugar-crush/memory/user/a.md' => 'bad']),
        );
        self::assertSame(
            '3 memory notes could not be read and were skipped (agent: 1, project: 2); they are not in the prompt'
                . ' — `/memory list <scope>` names each file and why',
            UnreadableNotes::notice([
                '/repo/.sugar-crush/memory/project/a.md' => 'bad',
                '/h/.sugar-crush/memory/project/b.md' => 'bad',
                '/h/.sugar-crush/memory/agent/c.md' => 'bad',
            ]),
        );
    }

    public function testRowsAreSortedOneLineValidUtf8AndClipped(): void
    {
        $rows = UnreadableNotes::rows([
            '/m/user/z.md' => "line one\nline two",
            "/m/user/a\xff.md" => str_repeat('r', 500),
        ]);

        self::assertSame(UnreadableNotes::SECTION_HEADER, $rows[0]);
        self::assertCount(3, $rows);
        self::assertStringStartsWith('- `/m/user/a', $rows[1], 'sorted by path');
        self::assertTrue(mb_check_encoding($rows[1], 'UTF-8'), 'an invalid byte in a path is scrubbed');
        self::assertStringEndsWith('…', $rows[1]);
        self::assertLessThanOrEqual(UnreadableNotes::REASON_MAX_CHARS, mb_strlen(explode(' — ', $rows[1], 2)[1]));
        self::assertSame('- `/m/user/z.md` — line one line two', $rows[2], 'a multi-line reason stays one row');
    }
}
