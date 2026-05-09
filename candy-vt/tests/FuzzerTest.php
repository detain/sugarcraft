<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Terminal\Terminal;

/**
 * Random-byte fuzzing — feeds large pseudo-random streams through the
 * full Parser → ScreenHandler pipeline and asserts no exception is
 * thrown. Doesn't validate output, only crash-safety on bad input.
 */
final class FuzzerTest extends TestCase
{
    /** Deterministic RNG so a failure can be reproduced from a single seed. */
    private function rng(int $seed): \Generator
    {
        mt_srand($seed);
        while (true) {
            yield mt_rand(0, 255);
        }
    }

    /**
     * @dataProvider seedAndLength
     */
    public function testFuzzerSurvivesRandomBytes(int $seed, int $length): void
    {
        $term = Terminal::create(cols: 80, rows: 24);
        $rng = $this->rng($seed);
        $bytes = '';
        for ($i = 0; $i < $length; $i++) {
            $bytes .= chr($rng->current());
            $rng->next();
        }
        // The contract: feed() never throws on any byte sequence.
        $term->feed($bytes);
        $term->flush();
        // Sanity: cursor stayed in bounds.
        $this->assertGreaterThanOrEqual(0, $term->cursor()->row);
        $this->assertLessThan(24, $term->cursor()->row);
        $this->assertGreaterThanOrEqual(0, $term->cursor()->col);
        $this->assertLessThan(80, $term->cursor()->col);
    }

    /** @return list<array{int, int}> */
    public static function seedAndLength(): array
    {
        return [
            'tiny'   => [42, 100],
            'small'  => [0xC0FFEE, 1024],
            'medium' => [0xDEADBEEF, 16 * 1024],
            'large'  => [0xBADF00D, 100 * 1024],
        ];
    }

    public function testFuzzerSurvivesEscapeStorm(): void
    {
        // Pathological case: lots of partial sequences that should all
        // be either consumed or recovered from cleanly.
        $term = Terminal::create(cols: 80, rows: 24);
        $patterns = [
            "\x1b[",        // CSI entry, no final
            "\x1b]",        // OSC start, no terminator
            "\x1bP",        // DCS start, no terminator
            "\x1b\\",       // stray ST
            "\x1b[?",       // private prefix without final
            "\x1b[?25",     // private number without h/l
            "\x1b]4;1;rgb:", // truncated palette
            "\x1b]8;;",     // hyperlink close
            "\x1b[1;2;3;4;5;6;7;8;9;10m", // many SGR params
            "\x1b[1A\x1b[2B\x1b[3C\x1b[4D", // a flurry of moves
        ];
        for ($i = 0; $i < 200; $i++) {
            $term->feed($patterns[$i % count($patterns)]);
        }
        $term->flush();
        $this->assertTrue(true);
    }
}
