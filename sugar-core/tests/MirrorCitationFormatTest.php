<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Grep gate for the AGENTS doc-comment law (sugar-core-073 / sc-34):
 * every `Mirrors` citation names a real upstream symbol in the exact
 * `Mirrors charmbracelet/<repo>.<Method>` form, and no placeholder is
 * ever shipped. Inventing an upstream name is the bug class this guards
 * both against: the only admissible fill for a citation is a verified
 * one, and where none exists the docblock says "original" out loud.
 */
final class MirrorCitationFormatTest extends TestCase
{
    /** @return list<string> */
    private function sourceFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(\dirname(__DIR__) . '/src', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        self::assertNotEmpty($files);

        return $files;
    }

    public function testNoPlaceholderUpstreamNamesAreShipped(): void
    {
        foreach ($this->sourceFiles() as $file) {
            $contents = (string) file_get_contents($file);
            self::assertStringNotContainsString(
                'charmbracelet/<repo>',
                $contents,
                $file . ': unfilled mirror placeholder (sugar-core-073)'
            );
        }
    }

    public function testEveryMirrorsCitationUsesTheExactRepoDotMethodForm(): void
    {
        $wellFormed = '/Mirrors charmbracelet\/[a-z0-9][a-z0-9\/.~-]*\.[A-Za-z][A-Za-z0-9]*/';
        $seen = 0;
        foreach ($this->sourceFiles() as $file) {
            $lines = file($file);
            self::assertIsArray($lines);
            foreach ($lines as $number => $line) {
                if (!preg_match('/\bMirrors charmbracelet\//', $line)) {
                    continue;
                }
                if (str_contains($line, '`')) {
                    // Meta-commentary ABOUT the convention (Ansi.php's
                    // honoured-only-where note) is not itself a citation.
                    continue;
                }
                $seen++;
                self::assertMatchesRegularExpression(
                    $wellFormed,
                    $line,
                    sprintf('%s:%d: spelling variant outside the canonical citation form', $file, $number + 1)
                );
            }
        }
        self::assertGreaterThan(15, $seen, 'the citation census regressed to scanning nothing');
    }
}
