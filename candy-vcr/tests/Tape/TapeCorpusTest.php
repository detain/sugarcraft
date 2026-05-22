<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Tape;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Tape\Compiler;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Regression test: parse all .tape files in the monorepo.
 */
final class TapeCorpusTest extends TestCase
{
    private static ?array $tapeFiles = null;

    public static function setUpBeforeClass(): void
    {
        $files = [];
        $root = dirname(__DIR__, 3);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'tape') {
                $files[] = $file->getPathname();
            }
        }

        self::$tapeFiles = $files;
    }

    public function testAllTapeFilesParseWithoutError(): void
    {
        $this->assertNotNull(self::$tapeFiles);
        $this->assertGreaterThan(800, count(self::$tapeFiles));

        $failures = [];
        foreach (self::$tapeFiles as $path) {
            $source = @file_get_contents($path);
            if ($source === false) {
                $failures[$path][] = 'Could not read file';
                continue;
            }

            $result = Compiler::parseSource($source);

            if (!empty($result['errors'])) {
                foreach ($result['errors'] as $error) {
                    $failures[$path][] = "Line {$error->line}: {$error->message}";
                }
            }
        }

        $this->assertEmpty(
            $failures,
            sprintf(
                "%d tape file(s) had parse errors:\n%s",
                count($failures),
                $this->formatFailures($failures),
            ),
        );
    }

    public function testAllTapeFilesCompileToCassette(): void
    {
        $this->assertNotNull(self::$tapeFiles);

        $failures = [];
        $compiler = new Compiler();

        foreach (self::$tapeFiles as $path) {
            $source = @file_get_contents($path);
            if ($source === false) {
                continue;
            }

            $result = Compiler::parseSource($source);
            if (!empty($result['errors'])) {
                continue;
            }

            try {
                $cassette = $compiler->compile($result['ast'], $path);
                $this->assertGreaterThanOrEqual(
                    0,
                    $cassette->header->cols,
                    "Invalid cols in {$path}",
                );
                $this->assertGreaterThanOrEqual(
                    0,
                    $cassette->header->rows,
                    "Invalid rows in {$path}",
                );
            } catch (\Throwable $e) {
                $failures[$path][] = $e->getMessage();
            }
        }

        $this->assertEmpty(
            $failures,
            sprintf(
                "%d tape file(s) failed to compile:\n%s",
                count($failures),
                $this->formatFailures($failures),
            ),
        );
    }

    public function testCorpusSize(): void
    {
        $this->assertNotNull(self::$tapeFiles);
        $this->assertGreaterThanOrEqual(
            841,
            count(self::$tapeFiles),
            'Expected at least 841 tape files (documented corpus size)',
        );
    }

    /**
     * @param array<string, list<string>> $failures
     */
    private function formatFailures(array $failures): string
    {
        $lines = [];
        foreach (array_slice($failures, 0, 20) as $path => $errors) {
            $relPath = str_replace(dirname(__DIR__, 3) . '/', '', $path);
            $lines[] = "  {$relPath}:";
            foreach (array_slice($errors, 0, 3) as $error) {
                $lines[] = "    - {$error}";
            }
            if (count($errors) > 3) {
                $lines[] = "    ... and " . (count($errors) - 3) . " more";
            }
        }
        if (count($failures) > 20) {
            $lines[] = "  ... and " . (count($failures) - 20) . " more files";
        }
        return implode("\n", $lines);
    }
}
