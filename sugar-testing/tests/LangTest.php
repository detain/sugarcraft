<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SugarCraft\Testing\Lang;

/**
 * Tests for the Lang translation facade.
 *
 * The load smoke-test guards the class against the visibility mismatch
 * between SugarCraft\Testing\Lang and its core base; the known-key test
 * guards actual translation (with placeholder interpolation) — a facade
 * that only ever fell back to raw keys would pass a load test alone.
 */
final class LangTest extends TestCase
{
    public function testLangClassLoads(): void
    {
        // This call would fatal if the class is unloadable due to the
        // visibility mismatch between SugarCraft\Testing\Lang and its base.
        $result = Lang::t('anything.key');

        $this->assertIsString($result);
    }

    public function testLangFallsBackToRawKeyWhenMissing(): void
    {
        // When a key is not found in any translation file, the T::translate
        // chain falls back to exact → base → en → raw key (dot-joined).
        // For 'testing.nonexistent.key', the raw fallback should return
        // 'testing.nonexistent.key' (the namespaced key itself).
        $result = Lang::t('nonexistent.key');

        $this->assertSame('testing.nonexistent.key', $result);
    }

    public function testLangReturnsKnownKeyValue(): void
    {
        // A key present in lang/en.php must return its translation — not the
        // raw key — with {placeholder} tokens substituted from the params.
        $result = Lang::t('input.invalid_arrow', ['dir' => 'left']);

        $this->assertSame('Invalid arrow direction: left', $result);
    }

    public function testEveryLangCallSiteHasAnEnglishKey(): void
    {
        // Localization discipline: keys are spelled literally at Lang::t
        // call sites, and this guard fails loud when a throw gains a key
        // without the matching en.php entry (or a stale call site survives
        // a rename).
        $keys = require __DIR__ . '/../lang/en.php';
        $this->assertIsArray($keys);

        $checked = 0;
        $files   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../src'));

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            foreach (preg_split('/\R/', $source) as $line) {
                if (preg_match('/Lang::t\(\s*\'([a-z0-9_.]+)\'/', $line, $match) === 1) {
                    $this->assertArrayHasKey(
                        $match[1],
                        $keys,
                        $file->getFilename() . ' calls Lang::t() for key "' . $match[1] . '" missing from lang/en.php',
                    );
                    $checked++;
                }
            }
        }

        $this->assertGreaterThan(20, $checked, 'the call-site scan found suspiciously few Lang::t uses — the pattern broke');
    }
}
