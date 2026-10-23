<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SugarCraft\Tetris\LaunchMode;

/**
 * Unit pins for the argv contract of `bin/candy-tetris` (docs-fix C1).
 *
 * The README advertises `-v`/`--vs`, the help screen advertises `-h`/`--help`,
 * and the shipped VS tapes invoke `./bin/candy-tetris -v`. Before this fix the
 * binary compared against bare `v`/`h`, so every documented form silently
 * booted the wrong engine. These tests pin the documented forms, the loud
 * refusal of everything else, and the tapes' own invocations.
 */
final class LaunchModeTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function vsFlags(): array
    {
        return [
            'short' => ['-v'],
            'long' => ['--vs'],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function helpFlags(): array
    {
        return [
            'short' => ['-h'],
            'long' => ['--help'],
        ];
    }

    public function testNoArgumentsStartsSinglePlayer(): void
    {
        self::assertSame(LaunchMode::SINGLE, LaunchMode::resolve(['candy-tetris']));
    }

    /**
     * @param string $flag
     *
     * @dataProvider vsFlags
     */
    public function testDocumentedVsFlagsStartVsMode(string $flag): void
    {
        self::assertSame(LaunchMode::VS, LaunchMode::resolve(['candy-tetris', $flag]));
    }

    /**
     * @param string $flag
     *
     * @dataProvider helpFlags
     */
    public function testDocumentedHelpFlagsRequestHelp(string $flag): void
    {
        self::assertSame(LaunchMode::HELP, LaunchMode::resolve(['candy-tetris', $flag]));
    }

    public function testBareLettersAreRefusedBecauseNoDocumentMentionsThem(): void
    {
        // The pre-fix parser accepted these; the README never did. They must
        // now fail by name instead of quietly booting a game. Captured per
        // iteration — expectException inside a loop would stop at the first.
        foreach (['v', 'h'] as $undocumented) {
            try {
                LaunchMode::resolve(['candy-tetris', $undocumented]);
                self::fail(sprintf('bare "%s" must be refused', $undocumented));
            } catch (InvalidArgumentException $refusal) {
                self::assertStringContainsString(sprintf('unknown mode "%s"', $undocumented), $refusal->getMessage());
            }
        }
    }

    public function testUnknownFlagIsRefusedByName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('candy-tetris: unknown mode "--puzzle"');
        LaunchMode::resolve(['candy-tetris', '--puzzle']);
    }

    public function testASecondArgumentIsRefusedByName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unexpected argument "-h"');
        LaunchMode::resolve(['candy-tetris', '-v', '-h']);
    }

    public function testHelpTextAdvertisesTheFlagsThatResolve(): void
    {
        $help = LaunchMode::helpText();

        // Every flag promised on the usage screen must actually resolve to the
        // mode it advertises — help text and parser can never drift silently.
        self::assertStringContainsString('-v, --vs', $help);
        self::assertStringContainsString('-h, --help', $help);
        self::assertSame(LaunchMode::VS, LaunchMode::resolve(['x', '-v']));
        self::assertSame(LaunchMode::VS, LaunchMode::resolve(['x', '--vs']));
        self::assertSame(LaunchMode::HELP, LaunchMode::resolve(['x', '-h']));
        self::assertSame(LaunchMode::HELP, LaunchMode::resolve(['x', '--help']));
    }

    public function testShippedVsTapesInvokeADocumentedVsFlag(): void
    {
        $tapes = glob(__DIR__ . '/../.vhs/vs-*.tape') ?: [];
        self::assertNotEmpty($tapes, 'the VS demo tapes must exist for this pin to mean anything');

        $checked = 0;
        foreach ($tapes as $tape) {
            $body = (string) file_get_contents($tape);
            preg_match_all('~Type\s+"([^"]*bin/candy-tetris\s+[^"]*)"~', $body, $matches);
            foreach ($matches[1] as $invocation) {
                $args = preg_split('/\s+/', trim(str_replace('./', '', $invocation))) ?: [];
                array_shift($args); // script name
                self::assertSame(
                    LaunchMode::VS,
                    LaunchMode::resolve(array_merge(['candy-tetris'], $args)),
                    sprintf('%s launches with "%s" — that form must boot VS mode', basename($tape), $invocation),
                );
                $checked++;
            }
        }

        self::assertGreaterThan(0, $checked, 'at least one tape invocation must have been checked');
    }
}
