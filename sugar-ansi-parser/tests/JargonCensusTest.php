<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Keeps campaign jargon out of the maintained surfaces of this library.
 *
 * Audit-and-fix campaigns leave residue when their identifiers — issue ids,
 * round/lane/weld tags, internal project names — are pasted into comments
 * and docs. None of them parse for a reader who meets this library on its
 * own, so a sweep scrubbed them all. This census is what keeps zero at zero:
 * any reintroduced token reddens the run that introduces it.
 *
 * The walk deliberately EXCLUDES CALIBER_LEARNINGS.md. Its section headings
 * are dated historical records, and campaign attribution there is the
 * accepted genre (a lesson's provenance is part of the lesson).
 */
final class JargonCensusTest extends TestCase
{
    /**
     * One pattern per campaign-token class. Each demands a continuation
     * character after its label, so none can match its own spelling inside
     * this file's regex literals. Byte notation such as 0x07 is also safe:
     * the hex digits sit inside a word, so the leading boundary never lands
     * before an embedded "E" + digits, and 0x-prefixed numbers never carry
     * a bare issue-id shape.
     *
     * @var array<string,string>
     */
    private const PATTERNS = [
        'issue id'          => '/\bE[0-9]{3}\b/',
        'round tag'         => '/\bround-[0-9]/',
        'lane tag'          => '/\blane-[a-z0-9]/',
        'weld tag'          => '/\bWELD-[A-Z0-9]/',
        'fleet project id'  => '/\bcrush_[a-z0-9]/',
        'docs campaign id'  => '/\bdocs[-]fix\b/',
    ];

    /** @var list<string> scan roots, relative to the library directory */
    private const ROOTS = ['src', 'tests', 'examples'];

    public function testTheCensusWalkActuallySeesTheMaintainedSurfaces(): void
    {
        $files = $this->maintainedFiles();
        $names = array_map(static fn (string $path): string => basename($path), $files);

        self::assertGreaterThanOrEqual(
            30,
            count($files),
            'the census walk must keep finding the maintained sources; a shrunken walk silently launders jargon back in',
        );
        foreach (['Parser.php', 'Transitions.php', 'ParserTest.php', 'debug.php', 'README.md'] as $expected) {
            self::assertContains($expected, $names, "the census walk lost {$expected}");
        }
    }

    public function testMaintainedSurfacesCarryNoCampaignJargon(): void
    {
        $offenders = [];
        $root = self::libraryRoot();

        foreach ($this->maintainedFiles() as $file) {
            $lines = file($file);
            self::assertIsArray($lines, "unreadable maintained surface: {$file}");

            $relative = ltrim(substr($file, strlen($root)), '/');
            foreach ($lines as $index => $line) {
                foreach (self::PATTERNS as $label => $pattern) {
                    if (preg_match($pattern, $line) === 1) {
                        $offenders[] = sprintf('%s:%d [%s] %s', $relative, $index + 1, $label, trim($line));
                    }
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "campaign jargon reintroduced on a maintained surface:\n" . implode("\n", $offenders),
        );
    }

    /**
     * @return list<string> absolute paths of every PHP/Markdown file under
     *                     the scan roots, plus the library README
     */
    private function maintainedFiles(): array
    {
        $root = self::libraryRoot();
        $files = [$root . '/README.md'];

        foreach (self::ROOTS as $directory) {
            $walker = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . '/' . $directory, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            foreach ($walker as $entry) {
                if ($entry->isFile() && preg_match('/\.(?:php|md)$/', $entry->getFilename()) === 1) {
                    $files[] = $entry->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    private static function libraryRoot(): string
    {
        return \dirname(__DIR__);
    }
}
