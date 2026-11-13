<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The error-prefix law (CT-S39, finding 059): the process name belongs to
 * the display layer ALONE. Thrown exception texts under src/ must not
 * self-brand, because the CLI wraps them in the branded `cli.storage_error`
 * envelope and a branded text would paint `candy-codetime:` twice.
 *
 * The only src/ lines allowed to carry the literal are the Store's direct
 * STDERR warnings (the warning IS a display surface) and the Renderer's
 * banner prefix (the dashboard display layer branding a caught message).
 */
final class ErrorBrandCensusTest extends TestCase
{
    public function testNoThrownTextUnderSrcCarriesTheProcessBrand(): void
    {
        $files = iterator_to_array(new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__ . '/../src', \FilesystemIterator::SKIP_DOTS),
        ));
        $this->assertNotEmpty($files);

        $offenders = [];
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $base = $file->getFilename();
            foreach (file($file->getPathname(), FILE_IGNORE_NEW_LINES) as $number => $line) {
                if (!str_contains($line, 'candy-codetime: ')) {
                    continue;
                }
                $allowed = ($base === 'Store.php' && str_contains($line, 'warning'))
                    || ($base === 'Renderer.php' && str_contains($line, 'loadError'));
                if (!$allowed) {
                    $offenders[] = $base . ':' . ($number + 1) . ' ' . trim($line);
                }
            }
        }

        $this->assertSame([], $offenders, 'brand literals outside the display layer:');
    }

    public function testTheStorageEnvelopeIsTheSingleBrandedSurfaceForThrownTexts(): void
    {
        $en = require __DIR__ . '/../lang/en.php';
        foreach (['cli.storage_error', 'cli.ignore_too_large'] as $key) {
            $this->assertStringStartsWith('candy-codetime: ', $en[$key]);
            $this->assertStringNotContainsString('candy-codetime: ', substr($en[$key], strlen('candy-codetime: ')), $key . ' carries the brand twice');
        }
    }
}
