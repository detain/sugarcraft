<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\Support\LogSafety;

/**
 * Pins for the lib's single operator-log neutralizer (027): control
 * bytes can neither terminate a log line nor bloat one, and the clip is
 * byte-exact because no multibyte marker may split a kept sequence.
 *
 * @covers \SugarCraft\GitServer\Support\LogSafety
 */
final class LogSafetyTest extends TestCase
{
    public function testControlRunsCollapseToSingleSpacesSoNothingOpensANewLine(): void
    {
        self::assertSame(
            'a b c [2Jd',
            LogSafety::token("a\x00\x01b\r\nc\x1B[2Jd\x7F"),
            'LF, CR, C0 controls, and DEL all fold into separator spaces'
        );
    }

    public function testClipIsByteExactAtTheDeclaredCeiling(): void
    {
        $clipped = LogSafety::token(\str_repeat('x', LogSafety::MAX_TOKEN_BYTES + 137));
        self::assertSame(LogSafety::MAX_TOKEN_BYTES, \strlen($clipped));

        // A legitimate token at exactly the ceiling survives whole — the
        // guard bounds hostile size, it must not truncate honest names.
        $exact = \str_repeat('y', LogSafety::MAX_TOKEN_BYTES);
        self::assertSame($exact, LogSafety::token($exact));
    }

    public function testAClientClaimThatErasesItselfStillPrintsAPlaceholder(): void
    {
        self::assertSame('<empty>', LogSafety::token(''));
        self::assertSame('<empty>', LogSafety::token("\x00\x1F \x7F"), 'the log line keeps its arity: no field ever vanishes');
    }
}
