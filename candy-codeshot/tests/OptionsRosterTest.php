<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Lockstep guard: the argv parser, the English usage text and the README
 * flag section must list exactly the same set of flag spellings. Adding a
 * flag to the CLI without documenting it (or documenting one that does not
 * exist) reddens this file rather than shipping the drift.
 */
final class OptionsRosterTest extends TestCase
{
    /** @return list<string> */
    private static function binFlags(): array
    {
        $bin = file_get_contents(__DIR__ . '/../bin/candy-codeshot');
        self::assertIsString($bin);
        preg_match_all("/case '(-[A-Za-z]|--[a-z][a-z-]*)'/", $bin, $m);
        $flags = array_values(array_unique($m[1]));
        sort($flags);

        return $flags;
    }

    /** @return list<string> */
    private static function usageFlags(): array
    {
        $en = require __DIR__ . '/../lang/en.php';
        self::assertIsArray($en);
        $flags = [];
        $inOptions = false;
        foreach (explode("\n", (string) $en['cli.usage']) as $line) {
            if (rtrim($line) === 'Options:') {
                $inOptions = true;
                continue;
            }
            if (!$inOptions) {
                continue;
            }
            if (preg_match('/^  (-[A-Za-z]), (--[a-z][a-z-]*)/', $line, $m)) {
                $flags[] = $m[1];
                $flags[] = $m[2];
            } elseif (preg_match('/^  (--[a-z][a-z-]*)/', $line, $m)) {
                $flags[] = $m[1];
            } elseif (trim($line) === '') {
                $inOptions = false;
            }
        }
        $flags = array_values(array_unique($flags));
        sort($flags);

        return $flags;
    }

    /** @return list<string> */
    private static function readmeFlags(): array
    {
        $readme = file_get_contents(__DIR__ . '/../README.md');
        self::assertIsString($readme);
        $parts = preg_split('/^### Flags$/m', $readme);
        self::assertIsArray($parts);
        self::assertCount(2, $parts, 'README must carry exactly one "### Flags" heading');
        $end = strpos($parts[1], "\n### ");
        $body = $end === false ? $parts[1] : substr($parts[1], 0, $end);
        preg_match_all('/`(-{1,2}[A-Za-z][A-Za-z0-9-]*)/', $body, $m);
        $flags = array_values(array_unique($m[1]));
        sort($flags);

        return $flags;
    }

    public function testUsageListsEveryParserFlagAndNothingMore(): void
    {
        $this->assertSame(
            self::binFlags(),
            self::usageFlags(),
            'cli.usage and the argv parser disagree on the flag roster',
        );
    }

    public function testReadmeListsEveryParserFlagAndNothingMore(): void
    {
        $this->assertSame(
            self::binFlags(),
            self::readmeFlags(),
            'README "### Flags" and the argv parser disagree on the flag roster',
        );
    }
}
