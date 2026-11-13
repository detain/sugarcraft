<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Vcr\Lang;

/**
 * Key-usage guard for candy-vcr's translation catalogue.
 *
 * LocaleParityTest proves the 16 files agree with each other; this proves
 * they agree with the code. A `Lang::t('typo.key')` in src/ would silently
 * render the raw key to users (T falls back to the key itself), and an
 * en.php entry nothing references is a dead string every translator pays for.
 * The scan is source-driven and fail-closed: it must find a floor of call
 * sites, so a scanner gone blind cannot pass vacuously.
 */
final class LangUsageTest extends TestCase
{
    private const CALL_SITE_FLOOR = 60;

    /** @return array<string, string> */
    private static function english(): array
    {
        $en = require \dirname(__DIR__) . '/lang/en.php';
        self::assertIsArray($en);

        return $en;
    }

    /** @return list<string> */
    private static function sourceFiles(): array
    {
        $files = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(\dirname(__DIR__) . '/src', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iter as $info) {
            if ($info instanceof \SplFileInfo && $info->isFile() && $info->getExtension() === 'php') {
                $files[] = $info->getPathname();
            }
        }
        \sort($files);

        return $files;
    }

    public function testEveryLangCallUsesAKeyThatExistsInEnglish(): void
    {
        $english = self::english();
        $sites = 0;
        $unknown = [];
        foreach (self::sourceFiles() as $file) {
            $source = \file_get_contents($file);
            self::assertIsString($source, $file);
            \preg_match_all('/Lang::t\(\s*\'([a-z_]+\.[a-z_]+)\'/', $source, $m);
            foreach ($m[1] as $key) {
                $sites++;
                if (!isset($english[$key])) {
                    $unknown[] = \basename($file) . ': ' . $key;
                }
            }
        }

        $this->assertGreaterThanOrEqual(self::CALL_SITE_FLOOR, $sites, 'the call-site scan lost coverage');
        $this->assertSame([], $unknown, "Lang::t keys missing from lang/en.php:\n" . \implode("\n", $unknown));
    }

    public function testNoEnglishKeyIsDead(): void
    {
        $corpus = '';
        foreach (self::sourceFiles() as $file) {
            $corpus .= (string) \file_get_contents($file);
        }

        $dead = [];
        foreach (\array_keys(self::english()) as $key) {
            if (!\str_contains($corpus, "'" . $key . "'")) {
                $dead[] = $key;
            }
        }

        $this->assertSame([], $dead, "lang/en.php keys no source file references:\n" . \implode("\n", $dead));
    }

    public function testNoCliLiteralCarriesTheToolPrefixAnymore(): void
    {
        // A "candy-vcr <verb>: ..." string literal in src/Cli would be an
        // untranslated diagnostic; the prefix belongs to cli.err/cli.unknown_option.
        $offenders = [];
        foreach (self::sourceFiles() as $file) {
            if (!\str_contains($file, '/src/Cli/')) {
                continue;
            }
            foreach (\file($file) ?: [] as $n => $line) {
                if (\preg_match('/[\'"]candy-vcr(?: [a-z-]+)?: /', $line) === 1 && !\str_contains($line, '*')) {
                    $offenders[] = \basename($file) . ':' . ($n + 1);
                }
            }
        }

        $this->assertSame([], $offenders, "untranslated candy-vcr diagnostics:\n" . \implode("\n", $offenders));
    }

    public function testEnglishRendersWithPlaceholdersInterpolated(): void
    {
        T::setLocale('en');

        $this->assertSame(
            'candy-vcr diff: unknown option --x',
            Lang::t('cli.unknown_option', ['verb' => 'diff', 'option' => '--x']),
        );
    }

    public function testAnotherLocaleActuallyTranslates(): void
    {
        T::setLocale('de');
        try {
            $this->assertSame('Verwendung:', Lang::t('cli.usage_label'));
            $this->assertSame(
                'candy-vcr diff: unbekannte Option --x',
                Lang::t('cli.unknown_option', ['verb' => 'diff', 'option' => '--x']),
            );
        } finally {
            T::setLocale('en');
        }
    }
}
