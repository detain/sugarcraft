<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\PixelGrid;

/**
 * The GD call idiom census (findings 016 + 044).
 *
 * Two hand-rolled idioms used to drift across this lib:
 *  - imagecreatefromstring() called WITHOUT the @-suppression leaks
 *    ext-gd's decode warning onto the output stream before the false
 *    check throws, so a corrupt payload paid for a loud refusal with
 *    warning spam on top (and a failOnWarning run with a red test).
 *  - imagepalettetotruecolor()'s bool return ignored in fifteen
 *    copy-pasted blocks: a refused conversion (allocation failure)
 *    rode on as a palette image and pixel readers sampled garbage.
 *
 * The law now: every decode site is `@imagecreatefromstring(` + an
 * explicit === false throw, and every truecolor conversion goes
 * through the single checked door {@see \SugarCraft\Image\Concerns\ForceTrueColor}.
 * This census greps the src tree so a new hand-rolled site goes red
 * at the test run, not at the next audit.
 */
final class GdCallIdiomCensusTest extends TestCase
{
    public function testEveryDecodeSiteSuppressesTheGdWarning(): void
    {
        $checked = 0;

        foreach ($this->srcFiles() as $file) {
            foreach (explode("\n", (string) file_get_contents($file)) as $no => $line) {
                if (!str_contains($line, 'imagecreatefromstring(')) {
                    continue;
                }

                $trimmed = ltrim($line);
                if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                    continue; // doc/prose mentions are not call sites
                }

                $checked++;
                $this->assertStringContainsString(
                    '@imagecreatefromstring(',
                    $line,
                    sprintf('%s:%d calls imagecreatefromstring() without @-suppression', basename($file), $no + 1)
                );
            }
        }

        $this->assertGreaterThan(10, $checked, 'the census must actually see the decode sites');
    }

    public function testPaletteConversionIsOnlyEverCalledByTheSharedDoor(): void
    {
        $door = 'Concerns' . DIRECTORY_SEPARATOR . 'ForceTrueColor.php';
        $doorCalls = 0;

        foreach ($this->srcFiles() as $file) {
            foreach (explode("\n", (string) file_get_contents($file)) as $no => $line) {
                if (!str_contains($line, 'imagepalettetotruecolor(')) {
                    continue;
                }

                $trimmed = ltrim($line);
                if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                    continue; // the helper's own prose may name the function
                }

                $this->assertSame(
                    $door,
                    basename(dirname($file)) . DIRECTORY_SEPARATOR . basename($file),
                    sprintf('%s:%d hand-rolls a palette conversion outside ForceTrueColor', basename($file), $no + 1)
                );
                $doorCalls++;
            }
        }

        $this->assertSame(1, $doorCalls, 'the shared door must hold exactly one live conversion call');
    }

    public function testCorruptPayloadFailsWithoutLeakingAWarning(): void
    {
        // The acceptance the census exists for: a refused decode throws —
        // and nothing else crosses the stream.
        $warnings = [];
        set_error_handler(
            /**
             * @param string $message
             */
            static function (int $severity, string $message) use (&$warnings): bool {
                // Under the @ operator PHP still invokes the handler but
                // zeroes error_reporting — that is the suppressed route.
                // Only a DISPLAYABLE warning is the leak this arm probes.
                if (error_reporting() & $severity) {
                    $warnings[] = $message;
                }

                return true;
            }
        );

        try {
            try {
                // A truncated PNG signature: GD declares "this is a PNG"
                // and then fails at the missing IHDR. (Arbitrary bytes
                // would not do — libgd happily sniffs some of them as a
                // valid WBMP, which is a decode SUCCESS, not a refusal.)
                PixelGrid::fromString("\x89PNG\r\n\x1a\n\x00\x00", 2, 2);
                $this->fail('corrupt bytes must be refused');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('decode', strtolower($e->getMessage()));
            }
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings, 'the decode refusal must not be preceded by ext-gd warnings');
    }

    /**
     * Every PHP source file under src/, recursively.
     *
     * @return list<string>
     */
    private function srcFiles(): array
    {
        $root = dirname(__DIR__) . '/src';
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        $this->assertNotEmpty($files, 'the census root must exist');

        return $files;
    }
}
